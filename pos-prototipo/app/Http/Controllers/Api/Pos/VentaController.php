<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\Sede;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\VentaRechazada;
use App\Services\VentaService;
use App\Support\PosRespuesta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VentaController extends Controller
{
    private const METODOS = ['efectivo', 'debito', 'credito', 'transferencia'];

    /**
     * POST /api/pos/v1/venta
     * Pantalla del plugin: {items:[{producto_id,cantidad,precio_unitario}], pagos:[{metodo,monto}],
     *                       descuento_pct, total, cajera_id, ...}
     * Script de la PoC:    {items:[{producto_id,cantidad}], pagos:[{metodo,monto}]}
     */
    public function store(Request $request, VentaService $servicio)
    {
        $v = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.producto_id' => 'required|integer',
            'items.*.cantidad' => 'required|integer|min:1',
            'pagos' => 'required|array|min:1',
            'pagos.*.metodo' => 'required|in:' . implode(',', self::METODOS),
            'pagos.*.monto' => 'required|numeric|min:0',
            'descuento_pct' => 'nullable|numeric|min:0|max:100',
            'total' => 'nullable|numeric|min:0',
            'cajera_id' => 'nullable|integer',
        ]);
        if ($v->fails()) {
            return PosRespuesta::error($v->errors()->first(), 422);
        }
        $datos = $v->validated();

        /** @var Sede $sede */
        $sede = $request->attributes->get('sede');
        $sesion = $request->user();

        // Caja compartida: la venta se atribuye a la cajera activa, si es válida en esta sede.
        $cajera = $sesion;
        if (! empty($datos['cajera_id']) && (int) $datos['cajera_id'] !== $sesion->id) {
            $otra = User::where('tenant_id', $sesion->tenant_id)->find($datos['cajera_id']);
            if (! $otra || ! $sede->permiteA($otra)) {
                return PosRespuesta::error('La cajera seleccionada no está asignada a esta sede.', 403);
            }
            $cajera = $otra;
        }

        // RB-3: no se vende sin una caja abierta en la sede.
        $turno = TurnoCaja::abiertaEn($sede);
        if (! $turno) {
            return PosRespuesta::error('No hay una caja abierta en esta sede. Abre la caja para vender.', 409);
        }

        // Igual que normalize_pagos() del plugin: se ignoran montos 0 y no se repiten métodos.
        $pagos = [];
        foreach ($datos['pagos'] as $p) {
            $monto = (int) round((float) $p['monto']);
            if ($monto <= 0) {
                continue;
            }
            if (isset($pagos[$p['metodo']])) {
                return PosRespuesta::error("Método de pago repetido: {$p['metodo']}.", 422);
            }
            $pagos[$p['metodo']] = ['metodo' => $p['metodo'], 'monto' => $monto];
        }
        if (! $pagos) {
            return PosRespuesta::error('Falta el pago.', 422);
        }

        try {
            $venta = $servicio->registrar(
                $sede,
                $cajera,
                $datos['items'],
                array_values($pagos),
                (float) ($datos['descuento_pct'] ?? 0),
                $turno,
                $sesion,
                isset($datos['total']) ? (int) round((float) $datos['total']) : null,
            );
        } catch (VentaRechazada $e) {
            return PosRespuesta::error($e->getMessage(), 409);
        }

        return PosRespuesta::ok([
            'venta_id' => $venta->id,
            'referencia_interna' => $venta->referencia,
            'referencia' => $venta->referencia,
            'subtotal' => $venta->subtotal_clp,
            'descuento' => $venta->descuento_clp,
            'total' => $venta->total_clp,
            'total_clp' => $venta->total_clp,
            'cajera' => $cajera->name,
        ], 'Venta registrada.', 201);
    }
}
