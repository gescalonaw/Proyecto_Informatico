# Pruebas de concepto (PoC) · E2 · POS Multisede Web

Entorno para correr y repetir las pruebas de concepto de la Revisión Preliminar de Diseño (E2).
Cada carpeta tiene su script y una subcarpeta `resultados/` donde quedan las salidas.
En Windows basta con hacer doble clic en los archivos `.bat`, en orden.

## 0. Preparar el computador (una vez)

| Herramienta | Para qué | Cómo instalar (PowerShell o CMD) |
|---|---|---|
| Python 3 | PoC-1, PoC-3, matriz y colas | `winget install Python.Python.3.12` |
| k6 | Solo PoC-2 (carga) | `winget install k6 --source winget` |

Después de instalar, cierra y vuelve a abrir la terminal. Luego ejecuta `0_verificar_entorno.bat`:
si muestra la versión de Python y de k6, está todo listo. Los scripts de Python no necesitan
librerías extra.

## 1. PoC-1 · ¿Se puede vender sin internet sin dejar stock negativo?

- **Ejecutar:** `1_poc1_offline.bat` (o `python poc1_offline\poc1_stock_offline.py 1000 2026`).
- **Qué hace:** simula 3 cajas de una sede vendiendo un producto con stock 5 durante un corte de
  hasta 30 min, 1.000 veces, con tres estrategias: A ingenua, B cuota por caja, C solo en línea.
- **Criterio (fijado en E1):** una sola corrida con stock negativo descarta la estrategia.
- **Resultado esperado:** A ≈ 63 % de violaciones (descartada); B 0 % y ≈ 0,14 ventas perdidas por
  corte (adoptada); C 0 % pero ≈ 1,29 ventas perdidas por corte.
- **Para entenderla:** cambia `STOCK`, `TERMINALES`, `CORTE_MAX` o `LAMBDA` al inicio del script y
  vuelve a correrla. B nunca debe violar; si lo hace, hay un error en la estrategia.
- **Límite:** simula la estrategia, no el sistema. Cuando exista el prototipo hay que probar la
  cuota sobre el código real.

## 2. PoC-2 · ¿Aguanta 11,25 req/s (carga de diseño) con p95 ≤ 300 ms?
1. **Ensayo:** `2_poc2_ensayo_con_mock.bat` levanta un servidor FALSO (`mock_api.py`) y le aplica
   carga durante 1 minuto. Sirve solo para comprobar que k6 funciona; **ese número no es la PoC-2**.
2. **Prueba real:** primero instala y levanta el prototipo con los archivos de la carpeta
   `prototipo\` (ver su README). Con el prototipo corriendo en otra ventana, ejecuta
   `2_poc2_contra_prototipo.bat`. Pide la URL, un token de cajera (Enter lo genera solo), la sede
   y la duración (30 min para el resultado; 2 min para ensayar).
3. El script `leer_resultado.py` muestra la mediana, el p95, el % de errores y si se cumple el
   criterio. Guarda el resumen en `poc2_carga\resultados\`.
4. Los códigos de barra de `carga.js` ya coinciden con los datos de prueba del prototipo. Si
   cambian los datos o la ruta de la API, actualiza la lista `CODIGOS` y la ruta en ese archivo.
5. Anota la CPU y la RAM del computador: el resultado depende del hardware.

## 3. PoC-3 · Costo y tiempo de alta de un negocio

- **Ejecutar:** `3_poc3_costo_y_alta.bat`.
- **Costo:** `costo.py` usa los precios supuestos del SRS. Reemplázalos por los cotizados en
  `COTIZACION`. Criterio: ≤ CLP 100.000/mes para 20 negocios.
- **Alta:** con el prototipo, 2 o 3 personas que no lo conozcan registran un negocio, crean una sede
  y una cajera, sin ayuda. Cronometra y anota los minutos en `registro_alta.csv`. Criterio: ≤ 5 min.

## 4. Matriz de decisión y modelo de colas

- `4_matriz_decision.bat`: recalcula la matriz y su sensibilidad. Si el equipo cambia un peso o un
  puntaje, edítalo en `matriz.py` y actualiza la diapositiva con la nueva salida.
- `5_modelo_colas.bat`: modelo M/M/c para decidir cuántos workers usar. Después de la PoC-2,
  córrelo con la tasa real y la mediana medida en vez del supuesto de 50 ms.

## Responsables sugeridos (según el reparto de E2)

| PoC | Responsable | Expone en E2 |
|---|---|---|
| PoC-1 offline | Javier (frontend y usabilidad) | Diapositiva 8 |
| PoC-2 carga y modelo de colas | Martín (operación) | Diapositivas 5–6 |
| PoC-3 costo y alta | Martín (operación) | Diapositiva 6 |
| Matriz y sensibilidad | Bastián (QA) | Diapositivas 9–10 |

Los scripts se prepararon con ayuda de IA (Claude); declararlo en la plantilla de uso de IA del ramo.
