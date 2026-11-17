# Validación con usuarios · POS Multisede Web (E3, tarea 8)


## 1. Objetivo y qué se quiere establecer
Determinar si personas que **no conocen el sistema** y tienen el perfil de cajera de un comercio pueden completar el flujo principal del POS sin ayuda, y qué tan usable lo perciben.

Preguntas de la validación:
1. ¿Completan sin ayuda las tareas del flujo principal (abrir caja, vender, pago mixto, cambio de cajera, cerrar caja)?
2. ¿Cuánto tardan y cuántos errores cometen en cada tarea?
3. ¿Qué tan fácil les parece el sistema (percepción)?
4. ¿Dónde se traban? (problemas de usabilidad, con su gravedad)

## 2. Participantes
- **Quiénes:** personas que no son del equipo, de los perfiles del SRS: dueño/a (P1), cajera/o (P2) y encargado/a de sede (P3), idealmente personal de PaVariar. Si faltan, personas con experiencia en caja o atención en comercio (riesgo R-3 del SRS: menos de 3 confirmados).
- **Cuántos:** 3 a 5 según el SRS; **5 es lo ideal**, porque el RNF-U-2 se expresa sobre 5 (80 % = 4 de 5). Con 5 personas se detecta la mayoría de los problemas de usabilidad (Nielsen y Landauer), pero **no alcanza para estimar porcentajes con precisión**; por eso se reportan intervalos de confianza.
- **Exclusión:** integrantes del grupo y personas que hayan visto el prototipo.
- **Muestra:** por conveniencia. Es una limitación y se declara.

## 3. Criterios de éxito (se fijan antes de ver los datos)
| Métrica | Criterio de éxito | Fuente |
|---|---|---|
| **Tarea principal (T3: venta de 3 productos con pago mixto) sin ayuda** | **≥ 80 % (≥ 4 de 5)** | RNF-U-2 del SRS |
| Puntaje SUS | ≥ 68 | RNF-U-2; promedio de la industria (Sauro, REF-14) |
| Otras tareas (T1, T2, T4, T5) sin ayuda | ≥ 60 % cada una (criterio de apoyo) |
| Facilidad percibida por tarea (SEQ, 1 a 7) | media ≥ 5,0 (criterio de apoyo) | Referencia habitual (media histórica ≈ 5,5) |

Si un criterio no se cumple, se dice y se propone un cambio de diseño.

## 4. Entorno
- Prototipo corriendo en el notebook de la demo, con los datos de prueba del Negocio 1 (datos inventados, sin datos reales).
- Cuentas: `cajera@negocio1.cl` (Cajera Ana, Tienda Centro y Tienda Mall) y `cajera2@negocio1.cl` (Cajera Bea, solo Tienda Centro). Clave `secreto123`. Dejar la caja de Tienda Centro **cerrada** al comenzar cada sesión.
- Códigos de barra de apoyo, impresos en una hoja: `7801000000001`, `7801000000002`, `7801000000003`.
- Un **moderador** (da las instrucciones y no ayuda salvo que se le pida) y un **observador** (anota). Ambos del equipo.
- Duración: 20 minutos por persona.

## 5. Tareas guiadas
Se leen en voz alta, una a una. No se explica cómo hacerlas.

| N.º | Tarea | Se considera completada cuando |
|---|---|---|
| T1 | "Inicia sesión como Cajera Ana en la sede Tienda Centro y abre la caja con $20.000." | La caja queda abierta |
| T2 | "Vende 3 productos (usa los códigos de la hoja) con 10 % de descuento y cobra en efectivo." | La venta queda registrada con el descuento y se muestra el comprobante |
| **T3 (principal)** | "Haz otra venta de **3 productos** y cobra la mitad en efectivo y la otra mitad con tarjeta." | La venta queda registrada con pago mixto y los pagos suman el total |
| T4 | "Ahora llega la Cajera Bea a seguir en la misma caja. Haz el cambio de cajera." | Bea queda como cajera activa con su clave |
| T5 | "Termina el turno: cierra la caja." | El cierre queda registrado con el arqueo ciego |
| T6 (opcional, P1 y P3) | "Entra al panel y encuentra cuánto se vendió hoy con tarjeta y si hubo descuadre." | Encuentra ambos datos |
| T7 (opcional, P3) | "Ajusta el stock de un producto en Tienda Centro e indica el motivo." | El ajuste queda registrado con motivo |

**Criterios de la tarea completada:**
- **Sí:** la completa sin ayuda.
- **Con ayuda:** la completa después de una pista del moderador.
- **No:** no la completa o se rinde (máximo 3 minutos por tarea).

## 6. Procedimiento de cada sesión
1. Saludo y consentimiento (ver sección 8). 1 minuto.
2. Perfil: cargo, años de experiencia en caja, uso de POS anteriores. 1 minuto.
3. Explicar: "se evalúa el sistema, no a usted; puede detenerse cuando quiera; piense en voz alta". 1 minuto.
4. Tareas T1 a T5 (y T6 si corresponde). Tras cada una: **SEQ** ("Qué tan fácil fue esta tarea, de 1 = muy difícil a 7 = muy fácil"). 12 minutos.
5. Cuestionario **SUS** (sección 7). 3 minutos.
6. Entrevista corta final: "¿qué fue lo más confuso y qué cambiaría?" y "¿le serviría controlar la caja y el stock por sede?" (el SRS también busca saber si el valor propuesto es relevante). 2 minutos.

## 7. Instrumentos
**Hoja de observación** (por persona y tarea): resultado (sí, con ayuda, no), tiempo en segundos (desde que termina de leerse la tarea hasta que la completa), errores (clics o acciones equivocadas), ayudas dadas, SEQ y comentarios.
Se registra en `resultados_tareas.csv` (una fila por persona y tarea). Los campos cubren lo que pide el Anexo B del SRS: fecha, perfil (P1 a P3), tareas, tiempos, errores, finalización sin ayuda, comentarios y referencia al consentimiento.

**Cuestionario SUS** (escala 1 = muy en desacuerdo a 5 = muy de acuerdo). Se registra en `resultados_sus.csv`.
1. Creo que me gustaría usar este sistema con frecuencia.
2. Encontré el sistema innecesariamente complejo.
3. Pensé que el sistema era fácil de usar.
4. Creo que necesitaría el apoyo de un técnico para poder usar este sistema.
5. Encontré que las diversas funciones del sistema estaban bien integradas.
6. Pensé que había demasiada inconsistencia en este sistema.
7. Imagino que la mayoría de las personas aprenderían a usar este sistema muy rápidamente.
8. Encontré el sistema muy engorroso de usar.
9. Me sentí muy seguro/a usando el sistema.
10. Necesité aprender muchas cosas antes de poder usar el sistema.

Puntaje SUS: en los ítems impares se resta 1 a la respuesta, en los pares se resta la respuesta a 5; se suma y se multiplica por 2,5 (rango de 0 a 100).

## 8. Consentimiento y ética (Anexo H del SRS; R1 C6)
Se usa el texto del Anexo H, con consentimiento **escrito y previo** (firma o confirmación por correo, con fecha):
> "Estoy participando voluntariamente en una prueba de un sistema de ventas e inventario desarrollado como proyecto académico de la Universidad de Concepción. Se me explicó el propósito, que registrarán mis acciones en pantalla y mis comentarios, que mis datos se anonimizarán, que no se recolectarán datos de mis clientes, que puedo retirarme en cualquier momento sin consecuencias y que puedo solicitar la eliminación de mis datos escribiendo a (correo de contacto del equipo). Nombre, firma o confirmación por correo y fecha."

- **Sin grabación** de rostro ni voz, salvo autorización explícita (SRS §6).
- **Anonimización:** en los resultados solo va el código (U1, U2…), el perfil y los años de experiencia. El nombre queda solo en el consentimiento, que no se sube al repositorio.
- **Correo de contacto del equipo** para pedir la eliminación de datos: **[Completar]**.
- No participan menores de edad. La persona de PaVariar debe saber que la sesión no afecta su trabajo y que su desempeño no se informa a nadie.

## 9. Análisis (script `analizar_validacion.py`)
- **Por tarea:** porcentaje que la completa sin ayuda, con intervalo de confianza del 95 % de Wilson (adecuado para n pequeño), mediana de tiempo, errores promedio y SEQ promedio.
- **Global:** porcentaje de tareas completadas sin ayuda y con ayuda.
- **SUS:** puntaje por persona, promedio y comparación con 68.
- **Veredicto:** cada criterio de la sección 3 se marca como cumplido o no.
- **Problemas de usabilidad:** se listan con su gravedad (0 a 4, escala de Nielsen) y la tarea afectada, a partir de los comentarios; se propone un cambio por cada problema grave (gravedad ≥ 3).

Cómo se corre: `python analizar_validacion.py resultados_tareas.csv resultados_sus.csv`.

## 10. Limitaciones que se declaran en la presentación
- Muestra pequeña y por conveniencia: los porcentajes tienen intervalos de confianza amplios; con 5 personas, un 80 % tiene un intervalo de 38 % a 96 %.
- Entorno controlado con datos inventados y un moderador presente, no una jornada real.
- El tiempo se mide con cronómetro del observador (error de ±2 s).
- Se valida usabilidad del flujo; no se valida el rendimiento (se mide aparte en la PoC-2, a 11,25 req/s de diseño) ni la seguridad.
- Los resultados orientan cambios de diseño; no son una prueba estadística de que el sistema es usable.
