# Iteraciones de diseño (E3) · POS Multisede Web

---

## Iteración 1 · Dimensionamiento: supuesto S-1 de 80 a 30 ventas por día por sede

| Campo | Contenido |
|---|---|
| **Disparador (validación con datos reales)** | S-1 era un supuesto del SRS (E1): 80 ventas/día/sede, con la nota "validar con extracto real de PaVariar". La diapositiva 3 de la E2 ya usaba 30 y avisaba que se validaría con el extracto. Al contrastarlo con los datos reales de PaVariar se vio que 80 era demasiado alto.|
| **Alternativas consideradas** | Mantener 80 (sin respaldo en datos y sobredimensiona el escenario). Bajar a 30 (respaldado por el extracto de PaVariar). |
| **Decisión** | S-1 = 30 ventas/día/sede. |
| **Trade-off** | Se sacrificó margen de sobredimensionamiento del escenario (menos volumen asumido) para obtener un escenario defendible con datos reales, por la restricción de que cada cifra del escenario debe estar fundamentada (R1 C2).|
| **Efecto verificado** | El extracto real de PaVariar contradijo el supuesto, y la carga de diseño se recalculó de 30 a 11,25 req/s (ver abajo). |

**Efecto en el SRS (recalculado con sus mismos supuestos):** RNF-R-2 (throughput) pasa de ≥ 30 req/s a ≥ 11,25 req/s; las ventas de la plataforma, de 48.000 a 18.000 por día; las líneas de venta, de 144.000 a 54.000 por día; y RNF-R-3 (volumen), de ≈ 52,6 a ≈ 19,7 millones de filas por año (≈ 3,9 GB al año y ≈ 12 GB con índices, con 200 B por fila y factor 3 del SRS). 

**Efecto en la carga de diseño (análisis de dimensionamiento):** S-1 = 30 ventas/sede/día lleva a 18.000 ventas/día para 200 negocios × 3 sedes, 90.000 solicitudes/día (5 por venta), 2,5 req/s promedio en 10 h, 7,5 req/s en punta (×3) y **11,25 req/s con margen de diseño (×1,5)**. Los 30 req/s de la PoC-2 pasaron a ser una prueba de estrés a 2,7 veces la carga de diseño. También cambia el volumen de datos: 54.000 líneas/día y 19,7 millones de líneas al año (S-2).

---

## Iteración 2 · Capacidad: servicio medido (50 → 17,2 ms) y número de workers

| Campo | Contenido |
|---|---|
| **Disparador (medición y supuestos validados)** | En la E2 el servicio se supuso en 50 ms y se dijo "mínimo 2 workers; usamos 4" (diapositiva 4). La PoC-2 en XAMPP (15/11, 54.000 solicitudes a 30 req/s) midió media 17,2 ms, mediana 15,2 ms y p95 20,7 ms: el supuesto estaba 3 veces por encima. Además, con S-1 = 30 ventas/sede/día la carga de diseño bajó a 11,25 req/s; la prueba (30 req/s) es 2,7 veces esa carga. |
| **Alternativas consideradas** | A 11,25 req/s: 1 worker, p95 predicho 36 ms (utilización 19 %), pero se satura sobre ~58 req/s y no tiene redundancia. 2 workers: 21 ms (utilización 10 %). 3 y 4 workers (4 es la decisión de la E2): 21 ms, con utilización de 6 % y 5 %. |
| **Decisión** | Dejar **2 workers** (el mínimo que ya indicaba la E2) en vez de los 4 usados como margen: cumplen con p95 ≈ 21 ms a la carga de diseño y sostienen unas 5 veces esa carga. Los 4 solo se justificarían por holgura para las escrituras (ventas con bloqueo de fila), que no se midieron. |
| **Trade-off** | Se sacrificó holgura de capacidad (2 workers en vez de 4) para obtener un servidor más simple y de menor consumo, sin comprometer el RNF-R-1: el p95 esperado (≈ 21 ms) está 14 veces bajo el límite de 300 ms. |
| **Efecto verificado** | p95 medido 20,7 ms ≤ 300 ms con 0,00 % de errores, a 30 req/s (2,7 veces la carga de diseño). El modelo (`poc/colas/colas.py`) coincide con la simulación y es conservador respecto de lo medido (63 ms predicho contra 20,7 ms medido con 1 worker). Detalle en `poc/colas/README_colas.md`. Referencia adicional: en octubre, con **1 worker** (SQLite), la misma prueba a 30 req/s dio p95 = 22,9 ms y 0 % de errores. **Límite:** la corrida de noviembre usó la configuración por defecto de XAMPP, no una limitada a 2 hilos; la cifra de 2 workers sale del modelo y de la corrida de octubre, y no de una corrida con 2 workers configurados. |

**Cómo presentar el efecto sin que se lea como validación circular:** el modelo usa el servicio medido (17,2 ms) como dato de entrada, así que con poca utilización su predicción coincide con ese valor por construcción. Decir "a 11,25 req/s, 2 workers: utilización de 10 % y p95 predicho de ≈ 21 ms; con 1 worker, 36 ms", y como evidencia independiente la corrida de octubre: con 1 worker, a 30 req/s (2,7 veces la carga de diseño), p95 = 22,9 ms.

**Debilidad a resolver antes de presentar:** si la decisión final sigue siendo 4 workers, la comisión puede decir que "el diseño no cambió". Se salva si el cambio es que **ahora la decisión es evidencia y no supuesto**, o si cambian el número de workers y verifican el efecto.

---
## Iteración 3 · Datos: MariaDB en el prototipo, PostgreSQL + RLS en el E4

Contexto: la E2 declaró **PostgreSQL** (ALT-1), el diagrama C4 L2 dice **MySQL 8**, la PoC-2 de octubre se hizo con SQLite y el prototipo corre en **MariaDB 11.8**. El diagrama debe actualizarse para que diga lo mismo que esta iteración.

| Campo | Contenido |
|---|---|
| **Disparador** | Entorno XAMPP homogéneo en los 6 computadores del equipo y plazo del MVP: PostgreSQL exigía instalar y configurar otro servidor en cada equipo. Además, las notas de la E2 ya indicaban "MySQL en vez del PostgreSQL de E1: para reutilizar el SQL del plugin". |
| **Alternativas consideradas** | (1) PostgreSQL desde el inicio (diseño E2): coherente con la arquitectura, pero más trabajo en un plazo corto. (2) SQLite (octubre): sin servidor, pero no sirve para varias cajas a la vez. (3) MariaDB (elegida): viene en XAMPP, es de la familia MySQL y mantiene bloqueo de fila con InnoDB. |
| **Decisión** | MariaDB 11.8 para el prototipo y la demo. **PostgreSQL + RLS (seguridad a nivel de fila) en el E4**. Las pruebas automáticas siguen con SQLite en memoria. |
| **Trade-off** | Se sacrificó el aislamiento en la base de datos (RLS de PostgreSQL) para obtener un entorno instalable por todos los integrantes, por la restricción de calendario. Mientras tanto, el aislamiento entre negocios lo da el filtro por negocio en la aplicación. |
| **Por qué el riesgo es acotado (hechos del código)** | La lógica usa Eloquent y `lockForUpdate()` para stock y caja (RB-1), que MariaDB/InnoDB y PostgreSQL implementan como bloqueo de fila. El driver `pgsql` ya viene configurado. Las consultas SQL a mano (`SUM`, `COUNT`, `COALESCE`, `CASE`) son estándar. Un punto a corregir al migrar: `DashboardController` usa `SUBSTR(ventas.created_at, 1, 13)`, que en PostgreSQL requiere convertir la fecha a texto. Las pruebas ya corren sobre SQLite y la demo sobre MariaDB. |
| **Efecto verificado** | PoC-2: p95 de 22,9 ms (SQLite, octubre) a 20,7 ms (MariaDB, 15/11), con 0 % de errores. Prueba de la última unidad en MariaDB: 20 cajas compran las últimas 5 unidades, con resultado 5 aceptadas, 15 rechazadas y stock final 0 (según la diapositiva de ciencias de la ingeniería del equipo). 8 pruebas de aislamiento entre negocios: 0 fugas. **Aún no verificado:** el comportamiento en PostgreSQL y el RLS; la migración no se ha hecho. |
| **Plan de migración (E4)** | 1) Agregar PostgreSQL al CI (contenedor de servicio en GitHub Actions) y correr las pruebas. 2) Corregir las consultas no portables (`SUBSTR` sobre fecha). 3) Activar RLS y repetir las 8 pruebas de aislamiento, la última unidad y la PoC-2. 4) Migrar los datos y actualizar los diagramas C4. |

**Cómo presentarla:** es una decisión de plazo con riesgo acotado y plan de mitigación, no una mejora medida. Gana fuerza si antes de la presentación se hace el paso 1 del plan.

---
