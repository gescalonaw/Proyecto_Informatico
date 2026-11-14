<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\Sede;
use App\Models\StockSede;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registro de venta (ex Auditoria::registrar_venta del plugin).
 * RB-1: el stock se lee con bloqueo de fila dentro de una transacción;
 *       si alguna línea dejaría stock < 0, se rechaza la venta completa.
 * RB-2: la suma de los pagos debe ser igual al total (subtotal − descuento).
 * Los precios salen siempre de la base de datos, nunca del navegador.
 */
class VentaService
{
    /**
     * @param  array  $items  [['producto_id'=>int, 'cantidad'=>int], ...]
     * @param  array  $pagos  [['metodo'=>string, 'monto'=>int], ...]
     */
    public function registrar(
        Sede $sede,
        User $cajera,
        array $items,
        array $pagos,
        float $descuentoPct = 0,
        ?TurnoCaja $turno = null,
        ?User $registradaPor = null,
        ?int $totalDeclarado = null,
    ): Venta {
        return DB::transaction(function () use ($sede, $cajera, $items, $pagos, $descuentoPct, $turno, $registradaPor, $totalDeclarado) {
            $subtotal = 0;
            $lineas = [];

            // Una línea por producto (si la pantalla manda el mismo producto dos veces, se suman).
            $porProducto = [];
            foreach ($items as $item) {
                $pid = (int) $item['producto_id'];
                $porProducto[$pid] = ($porProducto[$pid] ?? 0) + (int) $item['cantidad'];
            }
            // Orden fijo por producto para evitar interbloqueos entre dos ventas simultáneas.
            ksort($porProducto);

            foreach ($porProducto as $pid => $cantidad) {
                $producto = Producto::find($pid);
                if (! $producto || ! $producto->activo) {
                    throw new VentaRechazada("El producto $pid no existe en este negocio.");
                }

                $stock = StockSede::where('producto_id', $producto->id)
                    ->where('sede_id', $sede->id)
                    ->lockForUpdate()
                    ->first();

                if (! $stock || $stock->cantidad < $cantidad) {
                    $hay = $stock?->cantidad ?? 0;
                    throw new VentaRechazada("Stock insuficiente para {$producto->nombre} (quedan $hay).");
                }

                $stock->decrement('cantidad', $cantidad);
                $subtotal += $producto->precio_clp * $cantidad;
                $lineas[] = ['producto_id' => $producto->id, 'cantidad' => $cantidad, 'precio_unitario_clp' => $producto->precio_clp];
            }

            // Mismo redondeo que la pantalla (pos-carrito.js): CLP sin decimales, una sola vez.
            $pct = min(100.0, max(0.0, $descuentoPct));
            $descuento = (int) round($subtotal * $pct / 100);
            $total = max(0, $subtotal - $descuento);

            if ($totalDeclarado !== null && $totalDeclarado !== $total) {
                throw new VentaRechazada("El total cambió (pantalla: $totalDeclarado, sistema: $total). Actualiza el carrito.");
            }

            $pagado = array_sum(array_column($pagos, 'monto'));
            if ($pagado !== $total) {
                throw new VentaRechazada("Los pagos ($pagado) no suman el total ($total).");
            }

            $venta = Venta::create([
                'tenant_id' => $sede->tenant_id,
                'sede_id' => $sede->id,
                'usuario_id' => $cajera->id,
                'registrada_por' => $registradaPor?->id ?? $cajera->id,
                'turno_caja_id' => $turno?->id,
                'referencia' => 'VTA-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8)),
                'subtotal_clp' => $subtotal,
                'descuento_clp' => $descuento,
                'total_clp' => $total,
            ]);

            foreach ($lineas as $l) {
                DB::table('venta_lineas')->insert($l + ['venta_id' => $venta->id]);
            }
            foreach ($pagos as $p) {
                DB::table('venta_pagos')->insert(['venta_id' => $venta->id, 'metodo' => $p['metodo'], 'monto_clp' => $p['monto']]);
            }

            return $venta;
        });
    }
}
