# Modelo de colas (CI-1) · Aplicación de ciencias de la ingeniería

## Pregunta de diseño
¿Cuántos workers necesita el servidor para cumplir RNF-R-1 y RNF-R-2 (p95 ≤ 300 ms y < 1 % de errores a 30 req/s)?

## Formulación
Cola M/G/c: llegadas de Poisson (tasa λ = 30 req/s), c servidores idénticos (workers) y tiempo de servicio con media 1/μ y variabilidad cv.
- Utilización: ρ = λ / (c·μ). Debe ser < 1 o la cola crece sin límite.
- Probabilidad de esperar: fórmula Erlang C.
- Espera en cola: Wq = Erlang C / (c·μ − λ) · (1 + cv²)/2 (Allen-Cunneen; con cv = 1 es el M/M/c exacto de la E2).
- Espera p95: la cola de la espera decae como exp(−(c·μ − λ)·t / k), con k = (1 + cv²)/2.
- p95 de la respuesta ≈ espera p95 + p95 del servicio (cota conservadora).

## Supuestos (declarados)
1. Las llegadas son de Poisson (cajeras independientes).
2. Los workers son idénticos y comparten una sola cola.
3. El servicio es estable e independiente de la carga (no incluye bloqueos de fila ni contención de la BD).
4. El tiempo de servicio es lognormal; cv se estima con p95/mediana de k6.
5. Solo se modela la búsqueda de producto por código (la solicitud de mayor frecuencia).

## Procedimiento
1. Medir con k6 (PoC-2) en XAMPP, sin recrear la base durante la corrida.
2. Correr `python colas.py --json ..\poc2_carga\resultados\resumen_20261115_1717.json --sim` (con `--c N` si se conoce el número de workers).
3. Comparar el p95 predicho con el medido y decidir c.

## Verificación de la fórmula (hecha)
La simulación de eventos discretos (200.000 solicitudes por caso, incluida con `--sim`) coincide con la fórmula.
Con λ = 30, servicio 50 ms y cv = 1, 3 workers dan espera p95 de 51,8 ms (fórmula) contra 46,9 ms (simulación), y 4 workers dan 8,0 ms contra 7,1 ms.

## Resultados
Medición válida (PoC-2, 15/11 17:17): Apache 2.4 + MariaDB 11.8 + PHP 8.5, Intel Core i5-12450HX, 7,6 GiB RAM.
54.000 solicitudes a 30,0 req/s durante 30 min, 0,00 % de errores, media 17,2 ms, mediana 15,2 ms, p95 20,7 ms, p99 22,5 ms.
Variabilidad estimada del servicio: cv = 0,19 (el tiempo de servicio es mucho más regular que el exponencial de la E2).

| c (workers) | Utilización | Espera p95 | p95 respuesta predicho | Simulado |
|---|---|---|---|---|
| 1 | 51,5 % | 42,7 ms | 63,4 ms | 55,8 ms |
| 2 | 25,7 % | 4,5 ms | 25,2 ms | 25,5 ms |
| 3 | 17,2 % | 0 ms | 20,7 ms | 23,1 ms |
| 4 | 12,9 % | 0 ms | 20,7 ms | 22,9 ms |

Contraste con la medición: el p95 medido (20,7 ms) es menor que lo que predice el modelo con 1 worker (63,4 ms) y con 2 (25,2 ms), así que el modelo es conservador.
Con 3 o más workers la espera es casi 0 y la predicción coincide con el servicio medido por construcción; esa parte no es evidencia independiente.

Cambio respecto a la E2: se había supuesto 50 ms de servicio y se midieron 17,2 ms (3 veces menos). Con 50 ms, 2 workers daban p95 = 394 ms y no cumplían; con el servicio medido, 1 worker ya cumple (63 ms ≤ 300 ms).

Sensibilidad a la carga (p95 de respuesta predicho en ms; "inest." = la cola crece sin límite):

| λ (req/s) | 1 worker | 2 workers | 3 workers | 4 workers |
|---|---|---|---|---|
| 30 (diseño) | 63 | 25 | 21 | 21 |
| 60 | inest. | 39 | 24 | 21 |
| 90 | inest. | 71 | 31 | 23 |
| 120 | inest. | inest. | 42 | 27 |

## Efecto en el diseño
La carga de diseño (30 req/s) está cubierta con 1 worker, pero ese worker se satura sobre ~58 req/s (1/17,2 ms).
Se mantienen 4 workers: sostienen 4 veces la carga de diseño (120 req/s) con p95 ≈ 27 ms, y a 30 req/s la utilización es de 13 %, lo que deja holgura para las escrituras (ventas con bloqueo de fila), que no se midieron.
Hay un solo punto lento en la corrida: el máximo fue 3.987 ms, con p99 = 22,5 ms. Es un único valor aislado entre 54.000 y no afecta al p95, pero hay que poder explicarlo si la comisión lo pregunta (por ejemplo, primera solicitud con caché frío).

## Límites
- k6 genera llegadas a ritmo fijo y las cajeras reales llegan al azar: lo medido sale mejor que el modelo.
- En XAMPP/Windows "worker" son hilos de Apache, y el límite real es el número de núcleos y MariaDB. Hay que definir qué es c en el equipo de la demo.
- La venta (escritura con bloqueo de fila, RB-1) tiene otro tiempo de servicio que la búsqueda, y no se modeló.
