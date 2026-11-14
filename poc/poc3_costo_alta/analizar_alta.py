"""PoC-3 (parte 2) · Tiempo de alta de un negocio nuevo.

Protocolo: cada participante, sin ayuda, debe (1) registrar un negocio, (2) crear una sede
y (3) crear un usuario cajera. Cronometrar desde que abre la página hasta que el usuario
queda creado. Anotar los minutos (con decimales, p. ej. 3.5) en registro_alta.csv.
Criterio del SRS: alta <= 5 minutos.
Uso: python analizar_alta.py [registro_alta.csv]
"""
import csv, sys, statistics as st

def main():
    ruta = sys.argv[1] if len(sys.argv) > 1 else 'registro_alta.csv'
    filas = [f for f in csv.DictReader(open(ruta, encoding='utf-8')) if f['minutos'].strip()]
    if not filas:
        print('Aún no hay tiempos en', ruta, '— completa la columna "minutos".'); return
    t = [float(f['minutos'].replace(',', '.')) for f in filas]
    sin_ayuda = sum(1 for f in filas if f['completo_sin_ayuda'].strip().lower() in ('si', 'sí'))
    print(f"Participantes: {len(t)} · media {st.mean(t):.1f} min · máximo {max(t):.1f} min")
    print(f"Completaron sin ayuda: {sin_ayuda} de {len(t)}")
    print(f"Dentro de 5 min: {sum(1 for x in t if x <= 5)} de {len(t)}")
    print('CRITERIO CUMPLIDO' if max(t) <= 5 else 'CRITERIO NO CUMPLIDO (algún alta superó 5 min)')

if __name__ == '__main__':
    main()
