"""Analiza la validación con usuarios (E3, tarea 8).

Uso: python analizar_validacion.py resultados_tareas.csv resultados_sus.csv

resultados_tareas.csv: fecha,participante,perfil,tarea,resultado,tiempo_s,errores,ayudas,seq,consentimiento,comentario
  resultado: si | con_ayuda | no     seq: 1 a 7
resultados_sus.csv: participante,p1..p10   (respuestas de 1 a 5)

Criterios (plan_validacion.md, sección 3): tarea principal T3 >= 80 % sin ayuda (RNF-U-2 del SRS)
y SUS >= 68; de apoyo: las otras tareas >= 60 % y SEQ medio >= 5,0.
"""
import csv, math, statistics, sys
from collections import defaultdict

TAREA_PRINCIPAL = 'T3'  # venta de 3 productos con pago mixto (RNF-U-2)
UMBRAL_PRINCIPAL, UMBRAL_TAREA, UMBRAL_SUS, UMBRAL_SEQ = 0.80, 0.60, 68.0, 5.0


def wilson(k, n, z=1.96):
    """Intervalo de confianza del 95 % de Wilson para una proporción k de n."""
    if n == 0:
        return 0.0, 0.0
    p = k / n
    d = 1 + z * z / n
    centro = (p + z * z / (2 * n)) / d
    mitad = z * math.sqrt(p * (1 - p) / n + z * z / (4 * n * n)) / d
    return max(0.0, centro - mitad), min(1.0, centro + mitad)


def sus(resp):
    """Puntaje SUS (0 a 100) de 10 respuestas de 1 a 5."""
    return 2.5 * sum((r - 1) if i % 2 == 0 else (5 - r) for i, r in enumerate(resp))


def num(x):
    try:
        return float(x)
    except (TypeError, ValueError):
        return None


def main():
    if len(sys.argv) < 3:
        print(__doc__); sys.exit(1)
    filas = list(csv.DictReader(open(sys.argv[1], encoding='utf-8')))
    filas_sus = list(csv.DictReader(open(sys.argv[2], encoding='utf-8')))
    if not filas:
        print('No hay datos en', sys.argv[1]); sys.exit(1)

    por_tarea = defaultdict(list)
    for f in filas:
        por_tarea[f['tarea']].append(f)
    gente = {f['participante'] for f in filas}
    print(f"Participantes: {len(gente)} · observaciones: {len(filas)}\n")

    print("POR TAREA")
    print("tarea  n  sin_ayuda  IC95 %        con_ayuda  no  mediana_t  errores  SEQ   veredicto")
    total_si = total = 0
    ok_tareas = True
    principal_ok = None
    seqs_todas = []
    for t in sorted(por_tarea):
        fs = por_tarea[t]
        n = len(fs)
        si = sum(f['resultado'] == 'si' for f in fs)
        ca = sum(f['resultado'] == 'con_ayuda' for f in fs)
        no = sum(f['resultado'] == 'no' for f in fs)
        lo, hi = wilson(si, n)
        tiempos = [x for x in (num(f['tiempo_s']) for f in fs) if x is not None]
        errores = [x for x in (num(f['errores']) for f in fs) if x is not None]
        seq = [x for x in (num(f['seq']) for f in fs) if x is not None]
        seqs_todas += seq
        es_principal = (t == TAREA_PRINCIPAL)
        cumple = si / n >= (UMBRAL_PRINCIPAL if es_principal else UMBRAL_TAREA)
        if es_principal:
            principal_ok = cumple
        else:
            ok_tareas &= cumple
        total_si += si; total += n
        med = f"{statistics.median(tiempos):7.0f} s" if tiempos else "      -  "
        err = f"{statistics.mean(errores):5.1f}" if errores else "    -"
        sq = f"{statistics.mean(seq):4.1f}" if seq else "   -"
        print(f"{t:5}  {n:1d}  {100*si/n:7.0f} %  {100*lo:3.0f}-{100*hi:3.0f} %   {ca:7d}  {no:3d}  {med}  {err}  {sq}   "
              f"{'cumple' if cumple else 'NO CUMPLE'}{' (principal, RNF-U-2)' if es_principal else ''}")

    glob = total_si / total
    lo, hi = wilson(total_si, total)
    print(f"\nGLOBAL (informativo): {100*glob:.0f} % de las tareas completadas sin ayuda (IC95 % {100*lo:.0f}-{100*hi:.0f} %)")
    if principal_ok is None:
        print(f"AVISO: no hay datos de la tarea principal {TAREA_PRINCIPAL}.")

    seq_medio = statistics.mean(seqs_todas) if seqs_todas else None
    if seq_medio is not None:
        print(f"SEQ medio: {seq_medio:.1f} → {'cumple' if seq_medio >= UMBRAL_SEQ else 'NO CUMPLE'} (umbral {UMBRAL_SEQ})")

    if filas_sus:
        puntajes = [sus([float(r[f'p{i}']) for i in range(1, 11)]) for r in filas_sus]
        medio = statistics.mean(puntajes)
        print(f"\nSUS por persona: " + ", ".join(f"{r['participante']}={p:.1f}" for r, p in zip(filas_sus, puntajes)))
        print(f"SUS promedio: {medio:.1f} (n = {len(puntajes)}) → {'cumple' if medio >= UMBRAL_SUS else 'NO CUMPLE'} (umbral {UMBRAL_SUS})")

    print("\nVEREDICTO GENERAL:", "todos los criterios se cumplen" if
          principal_ok and ok_tareas and (seq_medio or 0) >= UMBRAL_SEQ and
          (not filas_sus or medio >= UMBRAL_SUS)
          else "hay criterios que no se cumplen: proponer cambios de diseño para esas tareas")
    print("Recordatorio: con n pequeño los porcentajes son orientativos; declarar los límites (plan, sección 10).")


if __name__ == '__main__':
    main()
