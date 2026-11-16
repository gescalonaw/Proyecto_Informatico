# Modelo de colas (CI-1) · Aplicación de ciencias de la ingeniería

## Pregunta de diseño
¿Cuántos workers necesita el servidor para cumplir RNF-R-1 (p95 ≤ 300 ms al escanear un producto) con la carga de diseño?

## Carga de diseño (supuestos S-0 a S-4, validados con extracto de PaVariar)
| Paso | Cálculo | Resultado |
|---|---|---|
| Sedes | 200 negocios × 3 sedes (S-0) | 600 sedes |
| Ventas al día | 600 × 30 ventas/sede (S-1) | 18.000 |
| Solicitudes al día | 18.000 × 5 solicitudes por venta | 90.000 |
| Promedio | 90.000 ÷ 36.000 s (10 h de atención) | 2,5 req/s |
| Punta | × 3 (factor de punta) | 7,5 req/s |
| Diseño | × 1,5 (margen) | **11,25 req/s** |

## Formulación
Cola M/G/c: llegadas de Poisson (tasa λ), c servidores idénticos (workers) y tiempo de servicio con media 1/μ y variabilidad cv.
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
2. Correr `python colas.py --json ..\poc2_carga\resultados\resumen_20261115_1717.json --lam 11.25 --sim` (con `--c N` si se conoce el número de workers).
3. Comparar el p95 predicho con el medido y decidir c.

## Verificación de la fórmula (hecha)
La simulación de eventos discretos (200.000 solicitudes por caso, incluida con `--sim`) coincide con la fórmula.
Con λ = 11,25 y el servicio medido, 1 worker da espera p95 de 14,9 ms (fórmula) contra 14,8 ms (simulación).

## Medición (PoC-2, 15/11 17:17)
Apache 2.4 + MariaDB 11.8 + PHP 8.5, Intel Core i5-12450HX, 7,6 GiB RAM.
54.000 solicitudes a 30,0 req/s durante 30 min, 0,00 % de errores, media 17,2 ms, mediana 15,2 ms, p95 20,7 ms, p99 22,5 ms.
Variabilidad estimada del servicio: cv = 0,19 (mucho más regular que el exponencial de la E2).

**La prueba se hizo a 30 req/s, que es 2,7 veces la carga de diseño (11,25 req/s).** Es una prueba de estrés: si el RNF se cumple a 30 req/s, se cumple a 11,25.
Se midió con la carga mayor antes de que el escenario se redimensionara de 80 a 30 ventas por sede.

## Resultados
Con el servicio medido (17,2 ms), p95 de la respuesta predicho:

| c (workers) | A carga de diseño (11,25 req/s): utilización / p95 | A la carga de la prueba (30 req/s): utilización / p95 |
|---|---|---|
| 1 | 19 % / 35,6 ms | 51 % / 63,4 ms |
| 2 | 10 % / 20,7 ms | 26 % / 25,2 ms |
| 3 | 6 % / 20,7 ms | 17 % / 20,7 ms |
| 4 | 5 % / 20,7 ms | 13 % / 20,7 ms |

Contraste con la medición: a 30 req/s el p95 medido (20,7 ms) es menor que lo que predice el modelo con 1 worker (63,4 ms) y con 2 (25,2 ms), así que el modelo es conservador.
Con 3 o más workers la espera es casi 0 y la predicción coincide con el servicio medido por construcción; esa parte no es evidencia independiente.

Cambios respecto a la E2: se había supuesto 50 ms de servicio y se midieron 17,2 ms (3 veces menos), y la carga de diseño quedó en 11,25 req/s. Con 50 ms y 11,25 req/s, 1 worker da p95 ≈ 416 ms y no cumple, y 2 workers dan 171 ms.

Sensibilidad a la carga (p95 de respuesta predicho en ms; "inest." = la cola crece sin límite):

| λ (req/s) | 1 worker | 2 workers | 3 workers | 4 workers |
|---|---|---|---|---|
| 11,25 (diseño) | 36 | 21 | 21 | 21 |
| 30 (prueba) | 63 | 25 | 21 | 21 |
| 60 | inest. | 39 | 24 | 21 |
| 90 | inest. | 71 | 31 | 23 |

## Efecto en el diseño
A 11,25 req/s, 1 worker ya cumple con p95 ≈ 36 ms (utilización de 19 %), pero un worker se satura sobre ~58 req/s y no tiene redundancia.
Con 2 workers el p95 predicho es ≈ 21 ms, la utilización es de 10 % y se sostienen unos 5 veces la carga de diseño (hasta ~58 req/s con p95 ≈ 39 ms).
Falta medir las escrituras (ventas con bloqueo de fila, RB-1), que tienen otro tiempo de servicio.
Hay un solo punto lento en la corrida: el máximo fue 3.987 ms, con p99 = 22,5 ms. Es un único valor aislado entre 54.000 y no afecta al p95, pero hay que poder explicarlo (por ejemplo, primera solicitud con caché frío).

## Límites
- k6 genera llegadas a ritmo fijo y las cajeras reales llegan al azar: lo medido sale mejor que el modelo.
- En XAMPP/Windows "worker" son hilos de Apache, y el límite real es el número de núcleos y MariaDB. Hay que definir qué es c en el equipo de la demo.
- La venta (escritura con bloqueo de fila, RB-1) tiene otro tiempo de servicio que la búsqueda, y no se modeló.
