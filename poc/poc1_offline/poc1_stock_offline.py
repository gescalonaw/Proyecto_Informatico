"""PoC-1 — ¿Se puede vender durante un corte de internet sin dejar stock negativo?

Simulación de eventos discretos de UNA sede con 3 terminales (cajas) que venden el
mismo SKU con stock inicial 5. Al empezar el corte, las 3 terminales quedan sin
conexión y cada una se reconecta en un minuto aleatorio (corte <= 30 min, supuesto S-5).
La demanda del SKU llega a cada terminal como un proceso de Poisson.

Se comparan tres estrategias:
  A) Ingenua:     la terminal offline vende mientras su copia local del stock lo permita.
  B) Cuota local: al cortar, el stock se reparte en cuotas por terminal; offline solo se
                  vende la cuota propia; el servidor reserva las cuotas no usadas.
  C) Solo online: sin conexión no se vende (la venta se pierde o se anota en papel).

Métricas por estrategia (N corridas):
  - corridas con stock final < 0 (violación del invariante RB-1)
  - ventas perdidas respecto del ideal = min(demanda, stock)
Uso: python3 poc1_stock_offline.py [N] [semilla]
"""
import random, sys, statistics as st

STOCK = 5          # stock inicial del SKU en la sede
TERMINALES = 3
CORTE_MAX = 30     # minutos (S-5)
LAMBDA = 0.10      # ventas del SKU por minuto y terminal (supuesto de estrés)

def eventos(rng):
    """Genera (minuto, terminal) de cada intento de venta y el minuto de reconexión."""
    reconexion = [rng.uniform(1, CORTE_MAX) for _ in range(TERMINALES)]
    evs = []
    for t in range(TERMINALES):
        m = rng.expovariate(LAMBDA)
        while m < CORTE_MAX:
            evs.append((m, t)); m += rng.expovariate(LAMBDA)
    evs.sort()
    return evs, reconexion

def simular(evs, recon, estrategia):
    servidor = STOCK
    vendidas = 0
    if estrategia == 'A':
        local = [STOCK] * TERMINALES; cola = [0] * TERMINALES; sincronizada = [False] * TERMINALES
    if estrategia == 'B':
        base, resto = divmod(STOCK, TERMINALES)
        cuota = [base + (1 if i < resto else 0) for i in range(TERMINALES)]
        servidor -= sum(cuota)                    # el servidor reserva todas las cuotas
        sincronizada = [False] * TERMINALES
    for m, t in evs:
        # reconexiones ocurridas antes de este evento
        for i in range(TERMINALES):
            if estrategia in 'AB' and recon[i] <= m and not sincronizada[i]:
                sincronizada[i] = True
                if estrategia == 'A':
                    servidor -= cola[i]; cola[i] = 0
                else:
                    servidor += cuota[i]; cuota[i] = 0   # devuelve la cuota no usada
        online = recon[t] <= m
        if online:
            if servidor > 0: servidor -= 1; vendidas += 1
        else:
            if estrategia == 'A' and local[t] > 0:
                local[t] -= 1; cola[t] += 1; vendidas += 1
            elif estrategia == 'B' and cuota[t] > 0:
                cuota[t] -= 1; vendidas += 1
            # C: offline no vende
    # fin del corte: todas sincronizan
    if estrategia == 'A':
        servidor -= sum(cola)
    if estrategia == 'B':
        servidor += sum(cuota)
    return servidor, vendidas

def main():
    n = int(sys.argv[1]) if len(sys.argv) > 1 else 1000
    rng = random.Random(int(sys.argv[2]) if len(sys.argv) > 2 else 2026)
    res = {k: {'viol': 0, 'perdidas': [], 'min_stock': 0} for k in 'ABC'}
    demandas = []
    for _ in range(n):
        evs, recon = eventos(rng)
        ideal = min(len(evs), STOCK); demandas.append(len(evs))
        for k in 'ABC':
            final, vend = simular(evs, recon, k)
            if final < 0: res[k]['viol'] += 1
            res[k]['min_stock'] = min(res[k]['min_stock'], final)
            res[k]['perdidas'].append(max(ideal - vend, 0))
            res[k].setdefault('sobreventa', []).append(max(vend - STOCK, 0))
    print(f'N={n} corridas · stock={STOCK} · {TERMINALES} terminales · corte<= {CORTE_MAX} min · demanda media={st.mean(demandas):.1f} intentos')
    nombres = {'A': 'Ingenua', 'B': 'Cuota local', 'C': 'Solo online'}
    for k in 'ABC':
        p = res[k]['perdidas']
        print(f"{k} {nombres[k]:12s} violaciones={res[k]['viol']:4d} ({100*res[k]['viol']/n:5.1f} %) "
              f"stock_min={res[k]['min_stock']:3d}  ventas_perdidas_media={st.mean(p):.2f}  "
              f"corridas_con_perdida={100*sum(1 for x in p if x>0)/n:5.1f} %  "
              f"sobreventa_media={st.mean(res[k]['sobreventa']):.2f}")

if __name__ == '__main__':
    main()
