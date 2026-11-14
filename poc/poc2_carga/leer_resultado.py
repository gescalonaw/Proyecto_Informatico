"""Lee el resumen que exporta k6 y dice si se cumple el criterio de la PoC-2.

Uso: python leer_resultado.py resultados\\resumen.json
"""
import json, sys

def main():
    ruta = sys.argv[1] if len(sys.argv) > 1 else 'resultados/resumen.json'
    m = json.load(open(ruta, encoding='utf-8'))['metrics']
    d = m['http_req_duration']
    d = d.get('values', d)
    f = m['http_req_failed']
    f = f.get('values', f)
    err = f.get('rate', f.get('value', 0.0))
    p95, med = d['p(95)'], d['med']
    reqs = m['http_reqs']; reqs = reqs.get('values', reqs)
    print(f"Solicitudes: {int(reqs['count'])} · tasa real: {reqs['rate']:.1f} req/s")
    print(f"Mediana: {med:.1f} ms · p95: {p95:.1f} ms · errores: {100*err:.2f} %")
    ok = p95 <= 300 and err < 0.01
    print('CRITERIO CUMPLIDO (p95 <= 300 ms y errores < 1 %)' if ok else 'CRITERIO NO CUMPLIDO')
    print(f"Para el modelo de colas: python ..\\colas\\colas.py {reqs['rate']:.0f} {med:.0f}")

if __name__ == '__main__':
    main()
