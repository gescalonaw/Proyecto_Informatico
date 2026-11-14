"""Matriz de decisión ponderada (E2) con análisis de sensibilidad.

Edita PESOS (en %) y PUNTAJES (escala 1-5) si el equipo cambia algún valor.
Uso: python matriz.py
"""
import random

PESOS = {'C1 Costo': 20, 'C2 Plazo MVP sem 14': 20, 'C3 Cortes de internet': 15,
         'C4 Seguridad y aislamiento': 15, 'C5 Mantenibilidad': 10,
         'C6 Legal y datos': 10, 'C7 Potencial comercial': 10}
CRIT = list(PESOS)
PUNTAJES = {  # en el orden de CRIT
    'ALT-1 SaaS online-first + cuota': [4, 4, 3, 4, 4, 4, 5],
    'ALT-2 PWA local-first':           [2, 1, 4, 3, 2, 3, 4],
    'ALT-3 Instalable on-premise':     [5, 3, 5, 3, 2, 3, 2],
    'ALT-4 Integrar Odoo':             [3, 2, 3, 4, 3, 4, 3],
}

def totales(pesos):
    s = sum(pesos.values())
    return {a: sum(pesos[c] * p for c, p in zip(CRIT, v)) / s for a, v in PUNTAJES.items()}

def ganador(t):
    return max(t, key=t.get)

def mostrar(titulo, t):
    print(titulo)
    for a, v in sorted(t.items(), key=lambda x: -x[1]):
        print(f"   {v:.2f}  {a}")

def main():
    base = totales(PESOS)
    mostrar('Totales con los pesos del equipo:', base)
    g = ganador(base)

    cambios = []
    for c in CRIT:
        for d in (-10, 10):
            w = dict(PESOS); w[c] = max(0, w[c] + d)
            if ganador(totales(w)) != g:
                cambios.append(f"{c} {d:+d} pp")
    print(f"\nCada peso ±10 pp ({2*len(CRIT)} casos): " +
          ('el ganador no cambia.' if not cambios else 'cambia en ' + ', '.join(cambios)))

    rng = random.Random(1); n = 10000; cuenta = {a: 0 for a in PUNTAJES}
    for _ in range(n):
        w = {c: rng.gammavariate(1, 1) for c in CRIT}   # Dirichlet uniforme
        cuenta[ganador(totales(w))] += 1
    print(f"\nCon {n} juegos de pesos al azar:")
    for a, k in sorted(cuenta.items(), key=lambda x: -x[1]):
        print(f"   {100*k/n:5.1f} %  {a}")

    print()
    escenarios = {
        'Cortes largos y frecuentes (C3=40, C2=5, C1=15)': {'C3 Cortes de internet': 40, 'C2 Plazo MVP sem 14': 5, 'C1 Costo': 15},
        'Sin plazo (C2=0)': {'C2 Plazo MVP sem 14': 0},
        'Costo pesa el doble (C1=40)': {'C1 Costo': 40},
        'Seguridad pesa el doble (C4=30)': {'C4 Seguridad y aislamiento': 30},
        'Sin comercializar (C7=0)': {'C7 Potencial comercial': 0},
    }
    for nombre, upd in escenarios.items():
        w = dict(PESOS); w.update(upd); t = totales(w)
        top = sorted(t.items(), key=lambda x: -x[1])[:2]
        print(f"{nombre}: gana {top[0][0].split()[0]} ({top[0][1]:.2f} vs {top[1][1]:.2f})")

if __name__ == '__main__':
    main()
