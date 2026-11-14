<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Models\Sede;
use App\Models\StockSede;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Inventario por sede (RF-4): consulta, alerta de stock bajo y ajuste manual con motivo. */
class InventarioController extends Controller
{
    /** Bajo este número de unidades el producto se marca como "stock bajo". */
    public const UMBRAL_BAJO = 5;

    public function index(Request $request)
    {
        $tenant = (int) $request->user()->tenant_id;
        $sedes = Sede::orderByRaw("CASE WHEN tipo = 'tienda' THEN 0 ELSE 1 END")->orderBy('nombre')->get(['id', 'nombre', 'tipo']);
        $sede = $sedes->firstWhere('id', (int) $request->query('sede')) ?? $sedes->first();
        $q = trim((string) $request->query('q', ''));
        $soloBajo = $request->boolean('bajo');
        $verCosto = $request->user()->rol === 'dueno'; // el costo y el margen los ve solo la dueña

        $productos = DB::table('productos')
            ->leftJoin('stock_sede', function ($j) use ($sede) {
                $j->on('stock_sede.producto_id', '=', 'productos.id')->where('stock_sede.sede_id', '=', $sede->id);
            })
            ->where('productos.tenant_id', $tenant)
            ->where('productos.activo', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('productos.nombre', 'like', "%$q%")
                ->orWhere('productos.sku', 'like', "%$q%")
                ->orWhere('productos.codigo_barras', $q)))
            ->when($soloBajo, fn ($w) => $w->whereRaw('COALESCE(stock_sede.cantidad, 0) <= ?', [self::UMBRAL_BAJO]))
            ->orderByRaw('COALESCE(stock_sede.cantidad, 0) ASC')
            ->orderBy('productos.nombre')
            ->select('productos.id', 'productos.sku', 'productos.codigo_barras', 'productos.nombre',
                'productos.precio_clp', 'productos.costo_clp', DB::raw('COALESCE(stock_sede.cantidad, 0) AS stock'))
            ->paginate(50)->withQueryString();

        $totales = DB::table('stock_sede')->join('productos', 'productos.id', '=', 'stock_sede.producto_id')
            ->where('stock_sede.tenant_id', $tenant)->where('stock_sede.sede_id', $sede->id)->where('productos.activo', true)
            ->selectRaw('COUNT(*) AS productos, COALESCE(SUM(stock_sede.cantidad),0) AS unidades,
                         SUM(CASE WHEN stock_sede.cantidad <= ? THEN 1 ELSE 0 END) AS bajos,
                         COALESCE(SUM(stock_sede.cantidad * productos.precio_clp),0) AS valor_venta', [self::UMBRAL_BAJO])
            ->first();

        $movimientos = DB::table('ajustes_stock AS a')
            ->join('productos', 'productos.id', '=', 'a.producto_id')
            ->join('users', 'users.id', '=', 'a.user_id')
            ->where('a.tenant_id', $tenant)->where('a.sede_id', $sede->id)
            ->orderByDesc('a.id')->limit(15)
            ->get(['a.*', 'productos.nombre AS producto', 'users.name AS usuario']);

        return view('admin.inventario.index', [
            'sedes' => $sedes, 'sede' => $sede, 'q' => $q, 'soloBajo' => $soloBajo, 'verCosto' => $verCosto,
            'productos' => $productos, 'totales' => $totales, 'movimientos' => $movimientos, 'umbral' => self::UMBRAL_BAJO,
        ]);
    }

    public function ajustar(Request $request)
    {
        $datos = $request->validate([
            'sede_id' => 'required|integer',
            'producto_id' => 'required|integer',
            'tipo' => 'required|in:entrada,salida,fijar',
            'cantidad' => 'required|integer|min:0|max:1000000',
            'motivo' => 'required|string|min:3|max:160',
        ], [
            'motivo.required' => 'Indica el motivo del ajuste (queda registrado).',
            'motivo.min' => 'El motivo debe tener al menos 3 caracteres.',
        ]);

        // Ambos se buscan con el filtro del negocio: si son de otro negocio, 404.
        $sede = Sede::findOrFail($datos['sede_id']);
        $producto = Producto::findOrFail($datos['producto_id']);
        $cantidad = (int) $datos['cantidad'];

        if ($datos['tipo'] !== 'fijar' && $cantidad < 1) {
            return back()->withErrors(['cantidad' => 'La cantidad debe ser al menos 1.'])->withInput();
        }

        $resultado = DB::transaction(function () use ($sede, $producto, $datos, $cantidad, $request) {
            // Misma regla que en la venta (RB-1): se bloquea la fila y el stock nunca queda negativo.
            $stock = StockSede::where('sede_id', $sede->id)->where('producto_id', $producto->id)->lockForUpdate()->first()
                ?? StockSede::create(['tenant_id' => $sede->tenant_id, 'sede_id' => $sede->id, 'producto_id' => $producto->id, 'cantidad' => 0]);

            $antes = (int) $stock->cantidad;
            $despues = match ($datos['tipo']) {
                'entrada' => $antes + $cantidad,
                'salida' => $antes - $cantidad,
                'fijar' => $cantidad,
            };
            if ($despues < 0) {
                return "No se puede sacar $cantidad: en {$sede->nombre} hay $antes.";
            }

            $stock->update(['cantidad' => $despues]);
            DB::table('ajustes_stock')->insert([
                'tenant_id' => $sede->tenant_id, 'sede_id' => $sede->id, 'producto_id' => $producto->id,
                'user_id' => $request->user()->id, 'tipo' => $datos['tipo'],
                'cantidad_anterior' => $antes, 'cantidad_nueva' => $despues, 'diferencia' => $despues - $antes,
                'motivo' => $datos['motivo'], 'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$antes, $despues];
        });

        if (is_string($resultado)) {
            return back()->withErrors(['cantidad' => $resultado])->withInput();
        }

        return back()->with('ok', "{$producto->nombre}: stock en {$sede->nombre} de {$resultado[0]} a {$resultado[1]}.");
    }
}
