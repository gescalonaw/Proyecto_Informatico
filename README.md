# POS Multisede Web · Proyecto Informático I (Grupo 5)

Prototipo del sistema: **POS web multi-negocio** con caja compartida por sede y **panel de administración**.
La pantalla de caja se recicló del plugin `pos-sistema-inventario` (V6.1); el backend es Laravel 12 con
aislamiento por negocio (multi-tenant). Corre en **XAMPP** (Apache + MySQL/MariaDB) en Windows.

> Repo **privado**: la pantalla POS viene del plugin de un cliente. No subir datos reales.

---

## 1. Requisitos (una vez)

| Herramienta | Para qué | Dónde |
|---|---|---|
| **XAMPP con PHP 8.4 o superior** | Apache, MySQL y PHP | [https://www.apachefriends.org](https://www.apachefriends.org/es/download.html) (la misma versión para todo el equipo) |
| k6 (opcional) | Pruebas de carga y de la última unidad | `winget install k6 --source winget` |
| Python 3 (opcional) | Scripts de las PoC | `winget install Python.Python.3.12` |

Composer **no** hace falta: se descarga dentro del proyecto (paso 4.2).

**Clona el repo fuera de OneDrive** (por ejemplo en `C:\dev`), porque `vendor/` tiene miles de archivos.

---

## 2. Configurar XAMPP (una vez)

### 2.1 Instalar
Instala XAMPP en `C:\xampp` (la ruta por defecto). Basta con los componentes **Apache**, **MySQL**, **PHP** y **phpMyAdmin**.
### 2.2 Encender los servicios
En el XAMPP Control Panel presiona **Start** en **Apache** y en **MySQL**. Ambos deben quedar en verde.


---

## 3. Instalación rápida (con los scripts)

```
cd C:\dev
git clone <URL-del-repo> pos-multisede
```

Dentro de la carpeta, doble clic en orden:

| Paso | Archivo | Qué hace |
|---|---|---|
| 1 | `1_instalar.bat` | Revisa PHP y extensiones, descarga Composer, instala dependencias (`vendor/`) y crea el `.env` |
| 2 | `2_preparar_xampp.bat` | Crea la base `pos_multisede`, carga los datos de prueba, deja la configuración en caché y publica el proyecto en Apache |


---

## 4. Usuarios de prueba (clave `secreto123`)

| Usuario | Rol | Dónde entra | Sedes |
|---|---|---|---|
| `dueno@negocio1.cl` | Dueña | Panel y POS | Todas (ve costos, márgenes y descuadres) |
| `encargado@negocio1.cl` | Encargado | Panel y POS | Todas (ve descuadres) |
| `cajera@negocio1.cl` (Cajera Ana) | Cajera | Solo POS | Tienda Centro y Tienda Mall |
| `cajera2@negocio1.cl` (Cajera Bea) | Cajera | Solo POS | Solo Tienda Centro |

Lo mismo existe con `negocio2`. Cada negocio tiene 1.000 productos y 3 sedes
(en el Negocio 1: 1 Bodega, 2 Tienda Centro, 3 Tienda Mall). Códigos de barra del Negocio 1:
`7801000000001` a `7801000001000`. El Negocio 1 trae **14 días de ventas y cierres sintéticos**
(datos inventados) para que el panel tenga qué mostrar; hoy parte sin caja abierta.

---

## 5. Qué hace el prototipo

**POS (pantalla del plugin):** login por sede, apertura de caja, búsqueda o escaneo, carrito, descuento,
pago mixto, comprobante, cambio de cajera con clave (caja compartida) y cierre con arqueo ciego.

**Panel:** ventas (por día, sede, medio de pago, cajera y top de productos), cierres de caja con
esperado / contado / descuadre y detalle del turno, inventario por sede con stock bajo y ajustes con motivo.

**Reglas que se cumplen y se prueban:** stock nunca negativo con bloqueo de fila (RB-1), pagos = total (RB-2),
sin caja abierta no se vende (RB-3), aislamiento entre negocios (RB-5, RNF-S-6), la cajera no ve costos ni
descuadres (RB-10), el precio lo calcula el servidor.

**Pendiente (no está):** usuarios, sedes, catálogo e importación en el panel; cambio, devolución, reimpresión
y favoritos en el POS; MFA y Argon2id (RNF-S-1); despliegue en VPS.

---

## 6. Pruebas y mediciones

Todos los comandos, dentro de `pos-prototipo`:

| Qué | Comando | Resultado esperado |
|---|---|---|
| Pruebas automáticas (SQLite en memoria, no tocan la demo) | `php artisan test` | 37 pruebas pasan |
| Token de cajera (8 h) | `php artisan poc:token` | Un texto largo |
| Abrir la caja de una sede | `php artisan poc:caja 2` | "Caja … abierta en la sede 2" |
| Ver o fijar el stock | `php artisan poc:stock 1 2` / `php artisan poc:stock 1 2 5` | Stock del producto 1 en la sede 2 |

**Última unidad (RB-1), con k6:**
```
php artisan poc:caja 2
php artisan poc:stock 1 2 5
php artisan poc:token
k6 run -e BASE_URL=http://localhost/pos-multisede/public -e TOKEN=<token> -e SEDE=2 ..\poc\poc2_carga\ultima_unidad.js
php artisan poc:stock 1 2
```
Esperado: 5 ventas aceptadas, 15 rechazadas y stock final 0.

**Carga (PoC-2), con k6:** primero 2 minutos para ensayar, después 30 minutos para el resultado.
```
k6 run -e BASE_URL=http://localhost/pos-multisede/public -e TOKEN=<token> -e SEDE=2 -e DURATION=2m --summary-export ..\poc\poc2_carga\resultados\resumen.json ..\poc\poc2_carga\carga.js
python ..\poc\poc2_carga\leer_resultado.py ..\poc\poc2_carga\resultados\resumen.json
```
Mientras corre, **no** ejecutes `migrate:fresh` (borra los tokens y todo da 401). Anota la CPU y la RAM del PC.

Los scripts `3_…`, `4_…`, `5_…` y `6_….bat` hacen lo mismo con doble clic. Las PoC de la E2
(offline, costo, matriz de decisión, modelo de colas) están en `poc/`.

---

## 7. Trabajo diario con Git

```
git pull
```

Después de `git pull`, dentro de `pos-prototipo`:

| Si cambió… | Ejecuta |
|---|---|
| `composer.lock` | `php composer.phar install` |
| `database/migrations` o `database/seeders` | `php artisan migrate:fresh --seed` (**reinicia los datos**) |
| Cualquier archivo de `config/` o tu `.env` | `php artisan config:cache` |

Para subir tus cambios:
```
git add .
git commit -m "Qué cambiaste"
git push
```

**No subir nunca:** `vendor/`, `.env`, `composer.phar`, logs ni bases de datos (ya están en `.gitignore`).

---
## 8. Pruebas de concepto (PoC) de la E2

Cada PoC responde una duda de diseño antes de construir. El criterio para aceptar o descartar se fijó en el SRS (§8.3).
Están en `poc/`, cada una con su `.bat` y una carpeta `resultados/`.

| PoC | Pregunta | Cómo se probó | Resultado | Estado |
|---|---|---|---|---|
| **PoC-1 · Venta sin internet** (`poc1_offline`) | ¿Se puede vender durante un corte de internet sin que el stock quede negativo? | Simulación: 3 cajas de una sede venden un producto con stock 5 durante un corte de hasta 30 min, 1.000 veces, con 3 estrategias. Basta una corrida con stock negativo para descartar una estrategia | A (ingenua): 63,2 % de corridas con stock negativo → descartada. **B (cuota por caja): 0 % y 0,14 ventas perdidas por corte → elegida.** C (solo en línea): 0 %, pero 1,29 ventas perdidas por corte | Hecha. Es una simulación de la estrategia, no del sistema: el modo offline (RF-11) aún no está en el prototipo |
| **PoC-2 · Carga** (`poc2_carga`) | ¿Aguanta 11,25 req/s (carga de diseño) con p95 ≤ 300 ms y menos de 1 % de errores? (RNF-R-1, RNF-R-2). Se probó a 30 req/s (2,7 veces) | k6 simula cajas buscando productos por código durante 30 min | Octubre (SQLite, 1 worker): 54.001 solicitudes, 0 % de errores, p95 = 22,9 ms. **Noviembre (Apache 2.4 + MariaDB 11.8 + PHP 8.5, Intel Core i5-12450HX / 7,6 GiB RAM): 54.000 solicitudes (30,0 req/s), 0,00 % de errores, mediana = 15,2 ms, p95 = 20,7 ms** | Hecha (p95 = 20,7 ms ≤ 300 ms, errores = 0,00 % < 1 %) |
| **PoC-3 · Costo y alta de negocio** (`poc3_costo_alta`) | ¿Operar cuesta ≤ CLP 100.000/mes para 20 negocios? ¿Un negocio nuevo se registra en ≤ 5 min? | `costo.py` suma servidor, respaldos y dominio. El alta se cronometra con 2–3 personas que no conocen el sistema | Costo ≈ CLP 28.400/mes con precios **supuestos** (falta cotizar). Alta: sin medir | Costo: reemplazar por la cotización real. Alta: pendiente, porque el panel todavía no tiene registro de negocio |
| **Matriz de decisión** (`matriz_decision`) | ¿Qué alternativa de arquitectura conviene? | 4 alternativas con 7 criterios ponderados, más análisis de sensibilidad | **ALT-1 (SaaS en línea con cuota local) gana con 3,95**; sigue ganando si cada peso cambia ±10 puntos (14 de 14 casos) y en el 89 % de 10.000 juegos de pesos al azar | Hecha |
| **Modelo de colas** (`colas`) | ¿Cuántos procesos (workers) necesita el servidor? | Modelo M/G/c con la carga de diseño de 11,25 req/s (S-0 a S-4) y el servicio medido en la PoC-2 (media 17,2 ms, cv = 0,19), verificado con simulación | A 11,25 req/s: 1 worker al 19 % de utilización (p95 ≈ 36 ms), 2 workers al 10 % (p95 ≈ 21 ms) y 4 workers al 5 %. La prueba (30 req/s) es 2,7 veces la carga de diseño. Detalle en `poc/colas/README_colas.md` | Hecha |

**Para correrlas** (necesitan Python; la PoC-2 además k6): doble clic en el `.bat` de cada una dentro de `poc/`.
La PoC-2 contra el prototipo se corre con `5_prueba_carga.bat` en la raíz.

## 9. Estructura

```
pos-multisede/
├── pos-prototipo/            Proyecto Laravel
│   ├── app/Http/Controllers/Api/Pos/   API con el contrato del plugin (/api/pos/v1)
│   ├── app/Http/Controllers/Admin/     Panel de administración
│   ├── app/Services/VentaService.php   Registro de venta (RB-1, RB-2)
│   ├── database/migrations/            Tablas (negocios, sedes, stock, ventas, caja, ajustes)
│   ├── database/seeders/               Datos de prueba e historial sintético
│   ├── public/pos/                     Pantalla POS reciclada del plugin (JS y CSS)
│   ├── resources/views/                Vista del POS y del panel
│   └── tests/Feature/                  Pruebas automáticas
├── poc/                      PoC de la E2 y scripts de carga (k6)
├── docs/                     Diagramas
└── 1_… 6_….bat               Instalación y verificación con doble clic
```
