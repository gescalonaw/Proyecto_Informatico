"""Modelo de colas para dimensionar workers (CI-1 del SRS) y compararlo con lo medido con k6.

Uso:
  python colas.py                              lambda=11,25 req/s (carga de diseno, S-0 a S-4), servicio=50 ms (supuesto)
  python colas.py 11.25 17                     lambda=11,25, servicio medio=17 ms
  python colas.py 11.25 17 0.2                 ... y variabilidad del servicio cv=0.2 (cv=1: exponencial)
  python colas.py --json resumen.json          lee el resumen de k6 (PoC-2) y compara modelo vs medido
  python colas.py --json resumen.json --lam 11.25   ... ademas calcula con la carga de diseno
  python colas.py --json resumen.json --c 4    ... indicando con cuantos workers se midio
  python colas.py 30 42 0.6 --sim              agrega una simulacion para verificar la formula

Que calcula, para c = 1 a 8 workers:
  - utilizacion rho = lambda / (c*mu)
  - probabilidad de esperar (Erlang C)
  - espera media y espera p95 en cola
  - p95 de la respuesta ~ espera p95 + p95 del servicio (cota conservadora)
Criterio del SRS: p95 total <= 300 ms al escanear un producto.

Modelo: M/G/c (llegadas de Poisson, servicio de variabilidad cv, c servidores iguales).
Se resuelve con la aproximacion de Allen-Cunneen sobre el M/M/c exacto: la espera se escala por
(1 + cv^2) / 2. Con cv = 1 es exactamente el M/M/c de la E2.
"""
import argparse, heapq, json, math, random

Z95 = 1.6449  # percentil 95 de la normal estandar


def erlang_c(c, a):
    suma = sum(a**k / math.factorial(k) for k in range(c))
    t = a**c / math.factorial(c) * c / (c - a)
    return t / (suma + t)


def analitico(lam, serv_ms, cv, c, serv_p95_ms=None):
    """Devuelve (rho, p_esperar, wq_media_ms, wq_p95_ms, resp_p95_ms) o None si es inestable."""
    mu = 1000.0 / serv_ms
    a = lam / mu
    if a >= c:
        return None
    pw = erlang_c(c, a)
    k = (1 + cv**2) / 2                       # Allen-Cunneen (llegadas Poisson: ca^2 = 1)
    wq = k * pw / (c * mu - lam) * 1000
    tasa = (c * mu - lam) / k                 # la cola de la espera decae mas lento si cv > 1
    wq95 = max(0.0, math.log(pw / 0.05) / tasa * 1000) if pw > 0.05 else 0.0
    if serv_p95_ms is None:                   # servicio lognormal con media y cv dados
        s2 = math.log(1 + cv**2)
        serv_p95_ms = serv_ms / math.sqrt(1 + cv**2) * math.exp(Z95 * math.sqrt(s2))
    return a / c, pw, wq, wq95, wq95 + serv_p95_ms


def simular(lam, serv_ms, cv, c, n=200_000, semilla=1):
    """Simulacion de eventos discretos: llegadas Poisson, servicio lognormal, c servidores."""
    rnd = random.Random(semilla)
    s2 = math.log(1 + cv**2)
    mu_ln = math.log(serv_ms / 1000) - s2 / 2
    libres = [0.0] * c                        # instante en que cada servidor queda libre
    heapq.heapify(libres)
    t, esperas, resp = 0.0, [], []
    for _ in range(n):
        t += rnd.expovariate(lam)
        s = rnd.lognormvariate(mu_ln, math.sqrt(s2)) if cv > 0 else serv_ms / 1000
        libre = heapq.heappop(libres)
        ini = max(t, libre)
        heapq.heappush(libres, ini + s)
        esperas.append(ini - t)
        resp.append(ini - t + s)
    esperas.sort(); resp.sort()
    p = lambda v, q: v[int(q * len(v))] * 1000
    return sum(esperas) / n * 1000, p(esperas, 0.95), p(resp, 0.95)


def leer_k6(ruta):
    m = json.load(open(ruta, encoding='utf-8'))['metrics']
    g = lambda x: x.get('values', x)
    d, f, r = g(m['http_req_duration']), g(m['http_req_failed']), g(m['http_reqs'])
    return dict(lam=r['rate'], media=d['avg'], mediana=d['med'], p95=d['p(95)'],
                err=f.get('rate', f.get('value', 0.0)), n=int(r['count']))


def cv_desde_p95(mediana, p95):
    """Estima cv suponiendo servicio lognormal: p95/mediana = exp(1,645*sigma)."""
    sigma = math.log(p95 / mediana) / Z95
    return math.sqrt(math.exp(sigma**2) - 1)


def tabla(lam, serv, cv, sim, cs=range(1, 9), serv_p95=None):
    print(f"lambda = {lam:.2f} req/s · servicio medio = {serv:.1f} ms (mu = {1000/serv:.1f} req/s por worker) · cv = {cv:.2f}")
    cab = "workers  utilizacion  P(esperar)  espera_media  espera_p95  p95_respuesta"
    print(cab + ("   | simulado: esp_p95  resp_p95" if sim else ""))
    for c in cs:
        r = analitico(lam, serv, cv, c, serv_p95)
        if r is None:
            print(f"{c:7d}  {'>= 100 %':>11}  inestable: la cola crece sin limite"); continue
        rho, pw, wq, wq95, resp95 = r
        linea = f"{c:7d}  {100*rho:10.1f} %  {pw:10.3f}  {wq:9.1f} ms  {wq95:7.1f} ms  {resp95:9.1f} ms"
        if sim:
            _, s_w95, s_r95 = simular(lam, serv, cv, c)
            linea += f"   | {s_w95:14.1f} {s_r95:11.1f}"
        print(linea)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('lam', nargs='?', type=float, default=11.25)
    ap.add_argument('serv_ms', nargs='?', type=float, default=50.0)
    ap.add_argument('cv', nargs='?', type=float, default=1.0)
    ap.add_argument('--json', help='resumen de k6 exportado con --summary-export')
    ap.add_argument('--lam', type=float, dest='lam_diseno', help='carga de diseno (req/s) para calcular con el servicio medido')
    ap.add_argument('--c', type=int, help='workers con los que se hizo la medicion (para comparar)')
    ap.add_argument('--sim', action='store_true', help='agrega simulacion de eventos discretos')
    a = ap.parse_args()

    if not a.json:
        tabla(a.lam, a.serv_ms, a.cv, a.sim)
        print("Nota: el p95 de la respuesta es una cota (espera p95 + servicio p95); contrastar con k6.")
        return

    k = leer_k6(a.json)
    cv = cv_desde_p95(k['mediana'], k['p95'])
    print(f"MEDIDO (k6): {k['n']} solicitudes · {k['lam']:.1f} req/s · media {k['media']:.1f} ms · "
          f"mediana {k['mediana']:.1f} ms · p95 {k['p95']:.1f} ms · errores {100*k['err']:.2f} %")
    if k['err'] >= 0.01:
        print("AVISO: errores >= 1 %. Las respuestas de error son rapidas y distorsionan la mediana y el p95:"
              " esta corrida NO sirve para validar el modelo (revisar token / base de datos).")
    print(f"Variabilidad estimada del servicio (lognormal a partir de p95/mediana): cv = {cv:.2f}\n")
    # el tiempo de servicio es la media medida (con poca carga, respuesta ~ servicio)
    if a.lam_diseno:
        print(f"== CARGA DE DISENO ({a.lam_diseno:.2f} req/s) con el servicio medido ==")
        tabla(a.lam_diseno, k['media'], cv, a.sim, serv_p95=k['p95'])
        print(f"\n== CARGA DE LA PRUEBA ({k['lam']:.2f} req/s, {k['lam']/a.lam_diseno:.1f} veces la de diseno) ==")
    tabla(k['lam'], k['media'], cv, a.sim, serv_p95=k['p95'])
    if a.c:
        r = analitico(k['lam'], k['media'], cv, a.c, k['p95'])
        print(f"\nCOMPARACION con c = {a.c} workers")
        if r is None:
            print("El modelo dice que con esa tasa y ese servicio el sistema es inestable, pero se midio estable: revisar supuestos.")
        else:
            pred = r[4]
            if r[3] == 0.0:
                print("  Nota: con espera casi 0 la prediccion es igual al p95 de servicio medido por construccion;"
                      " esta comparacion solo discrimina cuando hay espera (pocos workers).")
            print(f"  p95 predicho por el modelo: {pred:.1f} ms   |   p95 medido: {k['p95']:.1f} ms")
            if pred >= k['p95']:
                print("  El modelo es conservador (predice igual o peor que lo medido): sirve para dimensionar.")
            else:
                print("  El modelo predice MENOS que lo medido: faltan efectos por incluir (BD, bloqueos, CPU compartida).")
    print("\nLimite: k6 usa llegadas a ritmo fijo (constant-arrival-rate), mas ordenadas que las de cajeras"
          " reales (Poisson). Por eso lo medido suele salir mejor que el modelo.")


if __name__ == '__main__':
    main()
