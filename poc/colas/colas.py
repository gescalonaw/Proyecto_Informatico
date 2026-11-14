"""Modelo de colas M/M/c para dimensionar workers (CI-1 del SRS).

Uso:
  python colas.py                      -> lambda=30 req/s, servicio=50 ms (supuesto)
  python colas.py 30 42                -> lambda=30 req/s, servicio medido=42 ms (de la PoC-2)
Imprime, para 1 a 8 workers: utilización, probabilidad de esperar, espera media y
espera p95 en cola. Criterio del SRS: p95 total <= 300 ms al escanear un producto.
"""
import math, sys

def erlang_c(c, a):
    suma = sum(a**k / math.factorial(k) for k in range(c))
    t = a**c / math.factorial(c) * c / (c - a)
    return t / (suma + t)

def main():
    lam = float(sys.argv[1]) if len(sys.argv) > 1 else 30.0
    serv_ms = float(sys.argv[2]) if len(sys.argv) > 2 else 50.0
    mu = 1000.0 / serv_ms
    print(f"lambda = {lam} req/s · servicio medio = {serv_ms} ms (mu = {mu:.1f} req/s por worker)")
    print("workers  utilizacion  P(esperar)  espera_media  espera_p95")
    for c in range(1, 9):
        a = lam / mu
        if a >= c:
            print(f"{c:7d}  {'>= 100 %':>11}  inestable: la cola crece sin limite"); continue
        pw = erlang_c(c, a)
        wq = pw / (c * mu - lam) * 1000
        p95 = max(0.0, math.log(pw / 0.05) / (c * mu - lam) * 1000) if pw > 0.05 else 0.0
        print(f"{c:7d}  {100*a/c:10.1f} %  {pw:10.3f}  {wq:9.1f} ms  {p95:7.1f} ms")
    print("Nota: el p95 de la respuesta es aprox. espera p95 + tiempo de servicio; contrastar con k6.")

if __name__ == '__main__':
    main()
