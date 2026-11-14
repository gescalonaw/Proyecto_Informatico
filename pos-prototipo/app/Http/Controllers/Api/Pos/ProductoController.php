<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Support\PosRespuesta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    /**
     * GET /api/pos/v1/productos
     *   ?codigo_barras=780...   lectura con pistola (coincidencia exacta)
     *   ?search=arroz           buscador de la pantalla (nombre o SKU que contenga el texto)
     *   ?buscar=780...          ruta medida por la PoC-2 (código exacto o prefijo de SKU/nombre)
     *   &limit=48&offset=0
     * Nunca devuelve el costo (RB-10). El stock es el de la sede de la sesión.
     */
    public function index(Request $request)
    {
        $sede = $request->attributes->get('sede');
        $codigo = trim((string) $request->query('codigo_barras', ''));
        $texto = trim((string) $request->query('search', ''));
        $buscar = trim((string) $request->query('buscar', ''));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = max(0, (int) $request->query('offset', 0));

        $q = Producto::query()->where('productos.activo', true);

        if ($codigo !== '') {
            $q->where('productos.codigo_barras', $codigo);
        } elseif ($texto !== '') {
            $q->where(function (Builder $w) use ($texto) {
                $w->where('productos.nombre', 'like', '%' . $texto . '%')
                  ->orWhere('productos.sku', 'like', '%' . $texto . '%')
                  ->orWhere('productos.codigo_barras', $texto);
            });
        } elseif ($buscar !== '') {
            $q->where(function (Builder $w) use ($buscar) {
                $w->where('productos.codigo_barras', $buscar)
                  ->orWhere('productos.sku', 'like', $buscar . '%')
                  ->orWhere('productos.nombre', 'like', $buscar . '%');
            });
        }

        $total = (clone $q)->count();

        $productos = $q->leftJoin('stock_sede', function ($j) use ($sede) {
                $j->on('stock_sede.producto_id', '=', 'productos.id')
                  ->where('stock_sede.sede_id', '=', $sede->id);
            })
            ->orderBy('productos.nombre')
            ->offset($offset)
            ->limit($limit)
            ->get(['productos.id', 'productos.sku', 'productos.codigo_barras', 'productos.nombre',
                   'productos.precio_clp', 'stock_sede.cantidad as stock']);

        if ($codigo !== '' && $productos->isEmpty()) {
            return PosRespuesta::error('No se encontró un producto con ese código.', 404);
        }

        return PosRespuesta::ok([
            'productos' => $productos->map(fn ($p) => self::formato($p))->all(),
            'total' => $total,
        ]);
    }

    /** GET /api/pos/v1/producto/{id} */
    public function show(Request $request, int $id)
    {
        $sede = $request->attributes->get('sede');
        $p = Producto::query()
            ->where('productos.id', $id)
            ->leftJoin('stock_sede', function ($j) use ($sede) {
                $j->on('stock_sede.producto_id', '=', 'productos.id')
                  ->where('stock_sede.sede_id', '=', $sede->id);
            })
            ->first(['productos.id', 'productos.sku', 'productos.codigo_barras', 'productos.nombre',
                     'productos.precio_clp', 'stock_sede.cantidad as stock']);

        if (! $p) {
            return PosRespuesta::error('Producto no encontrado.', 404); // también si es de otro negocio
        }

        return PosRespuesta::ok(['producto' => self::formato($p)]);
    }

    /** Mismos campos que format_producto_list() del plugin (más los nombres del prototipo). */
    private static function formato($p): array
    {
        $stock = (int) ($p->stock ?? 0);

        return [
            'id' => (int) $p->id,
            'nombre' => $p->nombre,
            'sku' => $p->sku,
            'codigo_barras' => $p->codigo_barras ?: $p->sku,
            'precio' => (int) $p->precio_clp,
            'precio_clp' => (int) $p->precio_clp,
            'imagen_url' => '',
            'stock' => $stock,
            'stock_disponible' => $stock,
            'stock_total' => $stock,
        ];
    }
}
