<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sede;
use App\Support\Fmt;
use App\Support\Periodo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Dashboard de ventas (RF-10): totales del periodo por sede, cajera, medio de pago, día y producto. */
class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $tenant = (int) $request->user()->tenant_id;
        $periodo = Periodo::desde($request->query('periodo'));
        $sedes = Sede::orderBy('nombre')->get(['id', 'nombre', 'tipo']);
        $sedeId = (int) $request->query('sede', 0);
        if ($sedeId && ! $sedes->contains('id', $sedeId)) {
            $sedeId = 0;
        }

        // Todas las consultas parten de aquí: ventas de ESTE negocio en el periodo (y sede).
        $ventas = fn () => DB::table('ventas')
            ->where('ventas.tenant_id', $tenant)
            ->whereBetween('ventas.created_at', [$periodo->desdeUtc(), $periodo->hastaUtc()])
            ->when($sedeId, fn ($q) => $q->where('ventas.sede_id', $sedeId));

        $k = $ventas()->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_clp),0) AS total, COALESCE(SUM(descuento_clp),0) AS descuentos')->first();
        $kpi = [
            'total' => (int) $k->total,
            'n' => (int) $k->n,
            'ticket' => $k->n ? (int) round($k->total / $k->n) : 0,
            'descuentos' => (int) $k->descuentos,
        ];

        $porSede = $ventas()->join('sedes', 'sedes.id', '=', 'ventas.sede_id')
            ->groupBy('sedes.id', 'sedes.nombre')
            ->selectRaw('sedes.nombre AS etiqueta, COUNT(*) AS n, SUM(ventas.total_clp) AS total')
            ->orderByDesc('total')->get();

        $porCajera = $ventas()->join('users', 'users.id', '=', 'ventas.usuario_id')
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.name AS etiqueta, COUNT(*) AS n, SUM(ventas.total_clp) AS total')
            ->orderByDesc('total')->get();

        $porMetodo = $ventas()->join('venta_pagos', 'venta_pagos.venta_id', '=', 'ventas.id')
            ->groupBy('venta_pagos.metodo')
            ->selectRaw('venta_pagos.metodo AS metodo, COUNT(*) AS n, SUM(venta_pagos.monto_clp) AS total')
            ->orderByDesc('total')->get()
            ->map(fn ($r) => (object) ['etiqueta' => Fmt::metodo($r->metodo), 'n' => $r->n, 'total' => $r->total]);

        // Ventas por día en hora de Chile: se agrupa por hora UTC en la base y se pasa a día local aquí
        // (funciona igual en MySQL, MariaDB y SQLite, sin tablas de zonas horarias).
        $porHora = $ventas()->groupByRaw('SUBSTR(ventas.created_at, 1, 13)')
            ->selectRaw('SUBSTR(ventas.created_at, 1, 13) AS h, COUNT(*) AS n, SUM(ventas.total_clp) AS total')->get();
        $porDia = collect($periodo->dias())->mapWithKeys(fn ($d) => [$d => ['n' => 0, 'total' => 0]])->all();
        foreach ($porHora as $r) {
            $dia = Carbon::createFromFormat('Y-m-d H', $r->h, 'UTC')->setTimezone(Fmt::ZONA)->format('Y-m-d');
            if (isset($porDia[$dia])) {
                $porDia[$dia]['n'] += (int) $r->n;
                $porDia[$dia]['total'] += (int) $r->total;
            }
        }

        $topProductos = $ventas()->join('venta_lineas', 'venta_lineas.venta_id', '=', 'ventas.id')
            ->join('productos', 'productos.id', '=', 'venta_lineas.producto_id')
            ->groupBy('productos.id', 'productos.nombre', 'productos.sku')
            ->selectRaw('productos.nombre AS nombre, productos.sku AS sku, SUM(venta_lineas.cantidad) AS unidades, SUM(venta_lineas.cantidad * venta_lineas.precio_unitario_clp) AS total')
            ->orderByDesc('total')->limit(10)->get();

        // Cajas: abiertas ahora y descuadre acumulado del periodo.
        $cajasAbiertas = DB::table('turnos_caja')->join('sedes', 'sedes.id', '=', 'turnos_caja.sede_id')
            ->where('turnos_caja.tenant_id', $tenant)->where('turnos_caja.estado', 'abierta')
            ->when($sedeId, fn ($q) => $q->where('turnos_caja.sede_id', $sedeId))
            ->get(['turnos_caja.id', 'sedes.nombre AS sede', 'turnos_caja.abierta_en']);
        $descuadre = DB::table('turnos_caja')->where('tenant_id', $tenant)->where('estado', 'cerrada')
            ->whereBetween('cerrada_en', [$periodo->desdeUtc(), $periodo->hastaUtc()])
            ->when($sedeId, fn ($q) => $q->where('sede_id', $sedeId))
            ->selectRaw('COUNT(*) AS cierres, COALESCE(SUM(descuadre_efectivo_clp),0) AS neto, SUM(CASE WHEN descuadre_efectivo_clp <> 0 THEN 1 ELSE 0 END) AS con_descuadre')
            ->first();

        $stockBajo = DB::table('stock_sede')->join('productos', 'productos.id', '=', 'stock_sede.producto_id')
            ->where('stock_sede.tenant_id', $tenant)->where('productos.activo', true)
            ->where('stock_sede.cantidad', '<=', InventarioController::UMBRAL_BAJO)
            ->when($sedeId, fn ($q) => $q->where('stock_sede.sede_id', $sedeId))
            ->count();

        return view('admin.dashboard', compact(
            'periodo', 'sedes', 'sedeId', 'kpi', 'porSede', 'porCajera', 'porMetodo', 'porDia',
            'topProductos', 'cajasAbiertas', 'descuadre', 'stockBajo'
        ));
    }
}
