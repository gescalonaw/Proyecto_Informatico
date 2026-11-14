# POS Multisede Web · Proyecto Informático I (Grupo 5)

Prototipo del sistema: **POS web multi-negocio** con caja compartida por sede y **panel de administración**.
La pantalla de caja se recicló del plugin `pos-sistema-inventario` (V6.1); el backend es Laravel 12 con
aislamiento por negocio (multi-tenant). Corre en **XAMPP** (Apache + MySQL/MariaDB) en Windows.

> Repo **privado**: la pantalla POS viene del plugin de un cliente. No subir datos reales.

---

## 1. Requisitos (una vez)

| Herramienta | Para qué | Dónde |
|---|---|---|
| **XAMPP con PHP 8.4 o superior** | Apache, MySQL y PHP | https://www.apachefriends.org (la misma versión para todo el equipo) |
| **Git** | Clonar y subir cambios | https://git-scm.com |
| k6 (opcional) | Pruebas de carga y de la última unidad | `winget install k6 --source winget` |
| Python 3 (opcional) | Scripts de las PoC | `winget install Python.Python.3.12` |

Composer **no** hace falta: `1_instalar.bat` lo descarga dentro del proyecto.

**Clona el repo fuera de OneDrive** (por ejemplo en `C:\dev`), porque `vendor/` tiene miles de archivos.

## 2. Instalación (≈ 10–15 min la primera vez)

```
cd C:\dev
git clone <URL-del-repo> pos-multisede
```

Luego, dentro de la carpeta, doble clic en orden:

| Paso | Archivo | Qué hace |
|---|---|---|
| 1 | `1_instalar.bat` | Revisa PHP y extensiones, descarga Composer, instala dependencias (`vendor/`) y crea el `.env` |
| 2 | `2_preparar_xampp.bat` | Crea la base `pos_multisede`, carga los datos de prueba y publica el proyecto en Apache. Pide tener Apache y MySQL en verde en el panel de XAMPP |

Al terminar se abre el navegador. Direcciones:

- **POS (cajeras):** http://localhost/pos-multisede/public/pos/negocio-1 (y `/pos/negocio-2`)
- **Panel (dueña y encargado):** http://localhost/pos-multisede/public/admin

> Anoten cuánto se demoraron en instalar desde cero: es la evidencia de **RNF-P-1** (meta ≤ 30 min).

## 3. Usuarios de prueba (clave `secreto123`)

| Usuario | Rol | Dónde entra | Sedes |
|---|---|---|---|
| `dueno@negocio1.cl` | Dueña | Panel y POS | Todas (ve costos, márgenes y descuadres) |
| `encargado@negocio1.cl` | Encargado | Panel y POS | Todas (ve descuadres) |
| `cajera@negocio1.cl` (Cajera Ana) | Cajera | Solo POS | Tienda Centro y Tienda Mall |
| `cajera2@negocio1.cl` (Cajera Bea) | Cajera | Solo POS | Solo Tienda Centro |

Lo mismo existe con `negocio2`. Cada negocio tiene 1.000 productos y 3 sedes
(1 Bodega, 2 Tienda Centro, 3 Tienda Mall en el Negocio 1). Códigos de barra del Negocio 1:
`7801000000001` a `7801000001000`. El Negocio 1 trae **14 días de ventas y cierres sintéticos**
(datos inventados) para que el panel tenga qué mostrar; hoy parte sin caja abierta.

## 4. Qué hace el prototipo

**POS (pantalla del plugin):** login por sede, apertura de caja, búsqueda o escaneo, carrito, descuento,
pago mixto, comprobante, cambio de cajera con clave (caja compartida) y cierre con arqueo ciego.

**Panel:** ventas (por día, sede, medio de pago, cajera y top de productos), cierres de caja con
esperado / contado / descuadre y detalle del turno, inventario por sede con stock bajo y ajustes con motivo.

**Reglas que se cumplen y se prueban:** stock nunca negativo con bloqueo de fila (RB-1), pagos = total (RB-2),
sin caja abierta no se vende (RB-3), aislamiento entre negocios (RB-5, RNF-S-6), la cajera no ve costos ni
descuadres (RB-10), el precio lo calcula el servidor.

**Pendiente (no está):** usuarios, sedes, catálogo e importación en el panel; cambio, devolución, reimpresión
y favoritos en el POS; MFA y Argon2id (RNF-S-1); despliegue en VPS.

## 5. Scripts de verificación

| Archivo | Qué mide | Resultado esperado |
|---|---|---|
| `6_correr_pruebas.bat` | 37 pruebas automáticas (SQLite en memoria, no tocan la demo) | Todas pasan |
| `4_probar_ultima_unidad.bat` | RB-1: 20 cajas compran la última unidad a la vez (necesita k6) | 5 aceptadas, 15 rechazadas, stock 0 |
| `5_prueba_carga.bat` | PoC-2: 30 req/s, p95 ≤ 300 ms, errores < 1 % (necesita k6 y Python) | Se guarda en `poc/poc2_carga/resultados/` |
| `3_obtener_token.bat` | Token de cajera para probar la API a mano | — |

Las PoC de la E2 (offline, costo, matriz de decisión, modelo de colas) están en `poc/`, con sus `.bat`.

## 6. Trabajo diario con Git

```
git pull
```

- Si cambió `composer.lock` → ejecutar `1_instalar.bat`.
- Si hay migraciones o seeders nuevos → ejecutar `2_preparar_xampp.bat` (**reinicia los datos**).
- Si cambias el `.env` → ejecutar `C:\xampp\php\php.exe artisan config:cache` dentro de `pos-prototipo`.

**No subir nunca:** `vendor/`, `.env`, `composer.phar`, logs ni bases de datos (ya están en `.gitignore`).

```
git add .
git commit -m "Qué cambiaste"
git push
```

## 7. Estructura

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
└── 1_… 6_….bat               Instalación y verificación
```



