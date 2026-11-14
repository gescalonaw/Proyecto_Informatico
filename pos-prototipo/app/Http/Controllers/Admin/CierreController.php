<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sede;
use App\Models\TurnoCaja;
use App\Support\Periodo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Cierres de caja (RF-6): esperado vs. contado y descuadre por turno, con el detalle de sus ventas. */
class CierreController extends Controller
{
    public function index(Request $request)
    {
        $tenant = (int) $request->user()->tenant_id;
        $periodo = Periodo::desde($request->query('periodo', '30'));
        $sedes = Sede::orderBy('nombre')->get(['id', 'nombre']);
        $sedeId = (int) $request->query('sede', 0);
        $soloDescuadre = $request->boolean('descuadre');

        $turnos = DB::table('turnos_caja AS t')
            ->join('sedes', 'sedes.id', '=', 't.sede_id')
            ->join('users AS ua', 'ua.id', '=', 't.abierta_por')
            ->leftJoin('users AS uc', 'uc.id', '=', 't.cerrada_por')
            ->where('t.tenant_id', $tenant)
            ->whereBetween('t.abierta_en', [$periodo->desdeUtc(), $periodo->hastaUtc()])
            ->when($sedeId, fn ($q) => $q->where('t.sede_id', $sedeId))
            ->when($soloDescuadre, fn ($q) => $q->where('t.descuadre_efectivo_clp', '<>', 0))
            ->orderByDesc('t.abierta_en')
            ->select('t.*', 'sedes.nombre AS sede', 'ua.name AS abrio', 'uc.name AS cerro')
            ->paginate(20)->withQueryString();

        $resumen = DB::table('ventas')->whereIn('turno_caja_id', $turnos->pluck('id'))
            ->groupBy('turno_caja_id')
            ->selectRaw('turno_caja_id, COUNT(*) AS n, SUM(total_clp) AS total')
            ->get()->keyBy('turno_caja_id');

        return view('admin.cierres.index', compact('turnos', 'resumen', 'periodo', 'sedes', 'sedeId', 'soloDescuadre'));
    }

    public function show(Request $request, int $id)
    {
        $turno = TurnoCaja::findOrFail($id); // el scope de negocio devuelve 404 si es de otro negocio
        $sede = Sede::find($turno->sede_id);
        $nombres = DB::table('users')->where('tenant_id', $turno->tenant_id)->pluck('name', 'id');

        $ventas = DB::table('ventas')->where('turno_caja_id', $turno->id)->orderBy('created_at')->get();
        $ids = $ventas->pluck('id');
        $pagos = DB::table('venta_pagos')->whereIn('venta_id', $ids)->get()->groupBy('venta_id');
        $lineas = DB::table('venta_lineas')->join('productos', 'productos.id', '=', 'venta_lineas.producto_id')
            ->whereIn('venta_id', $ids)
            ->get(['venta_lineas.venta_id', 'venta_lineas.cantidad', 'venta_lineas.precio_unitario_clp', 'productos.nombre'])
            ->groupBy('venta_id');

        $porMetodo = DB::table('venta_pagos')->whereIn('venta_id', $ids)
            ->groupBy('metodo')->selectRaw('metodo, SUM(monto_clp) AS total')->pluck('total', 'metodo');
        $porCajera = $ventas->groupBy('usuario_id')->map(fn ($v, $uid) => (object) [
            'nombre' => $nombres[$uid] ?? '—', 'n' => $v->count(), 'total' => $v->sum('total_clp'),
        ])->sortByDesc('total');

        return view('admin.cierres.show', compact('turno', 'sede', 'nombres', 'ventas', 'pagos', 'lineas', 'porMetodo', 'porCajera'));
    }
}
