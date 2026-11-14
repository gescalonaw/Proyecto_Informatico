<?php

namespace Database\Seeders;

use App\Models\Sede;
use App\Models\User;
use App\Services\VentaRechazada;
use App\Services\VentaService;
use App\Support\Fmt;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Historial SINTÉTICO de 14 días para el Negocio 1, para que el panel tenga qué mostrar en la demo.
 * Las ventas pasan por el mismo VentaService que usa la caja (descuentan stock y validan pagos),
 * cada día tiene su caja abierta y cerrada, y algunos cierres tienen descuadre.
 * Es reproducible (semilla fija). Hoy queda sin caja abierta, para abrirla en vivo.
 */
class HistoriaSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(2026);
        $tenant = (int) DB::table('tenants')->where('slug', 'negocio-1')->value('id');
        TenantContext::set($tenant);

        $u = fn ($email) => User::where('email', $email)->first();
        $ana = $u('cajera@negocio1.cl');
        $bea = $u('cajera2@negocio1.cl');
        $encargado = $u('encargado@negocio1.cl');
        $centro = Sede::where('codigo', 'CEN')->first();
        $mall = Sede::where('codigo', 'MALL')->first();
        $productos = DB::table('productos')->where('tenant_id', $tenant)->orderBy('id')->limit(120)->pluck('id')->all();

        // Algunos productos de alta rotación parten con poco stock en Tienda Centro:
        // con las ventas de estas dos semanas quedan bajo el umbral (alerta de stock bajo).
        foreach ([5 => 34, 7 => 43, 12 => 51, 18 => 35, 25 => 41] as $i => $inicial) {
            DB::table('stock_sede')->where('sede_id', $centro->id)->where('producto_id', $productos[$i])->update(['cantidad' => $inicial]);
        }

        $servicio = app(VentaService::class);
        $hoy = Carbon::now(Fmt::ZONA)->startOfDay();

        for ($diasAtras = 14; $diasAtras >= 1; $diasAtras--) {
            $dia = $hoy->copy()->subDays($diasAtras);
            foreach ([[$centro, [$ana, $bea], 30], [$mall, [$ana], 18]] as [$sede, $cajeras, $base]) {
                $this->dia($servicio, $sede, $cajeras, $encargado, $dia, $base + mt_rand(-6, 6), $productos);
            }
        }

        // Algunos ajustes manuales en Tienda Centro, para el historial de la vista de inventario.
        Carbon::setTestNow($hoy->copy()->subDay()->setTime(19, 10)->utc());
        $this->ajuste($tenant, $centro->id, $productos[1], $encargado->id, 'entrada', 24, 'Recepción proveedor');
        $this->ajuste($tenant, $centro->id, $productos[3], $encargado->id, 'salida', 2, 'Merma: envase roto');
        $hay = (int) DB::table('stock_sede')->where('sede_id', $centro->id)->where('producto_id', $productos[10])->value('cantidad');
        $this->ajuste($tenant, $centro->id, $productos[10], $encargado->id, 'fijar', $hay - 1, 'Conteo físico: faltaba 1');
        Carbon::setTestNow();
        TenantContext::set(null);
    }

    private function dia(VentaService $servicio, Sede $sede, array $cajeras, User $encargado, Carbon $dia, int $n, array $productos): void
    {
        $apertura = $dia->copy()->setTime(9, 0);
        Carbon::setTestNow($apertura->copy()->utc());
        $turnoId = DB::table('turnos_caja')->insertGetId([
            'tenant_id' => $sede->tenant_id, 'sede_id' => $sede->id, 'estado' => 'abierta',
            'abierta_por' => $cajeras[0]->id, 'abierta_en' => now(), 'efectivo_inicial_clp' => 20000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $turno = \App\Models\TurnoCaja::find($turnoId);

        $minutos = [];
        for ($i = 0; $i < $n; $i++) {
            $minutos[] = mt_rand(30, 11 * 60); // 09:30 a 20:00
        }
        sort($minutos);

        foreach ($minutos as $m) {
            Carbon::setTestNow($apertura->copy()->addMinutes($m)->utc());
            $items = [];
            for ($l = 0, $nl = mt_rand(1, 4); $l < $nl; $l++) {
                // Los primeros 40 productos se venden más (productos de alta rotación).
                $idx = mt_rand(1, 100) <= 70 ? mt_rand(0, 39) : mt_rand(40, count($productos) - 1);
                $items[] = ['producto_id' => $productos[$idx], 'cantidad' => mt_rand(1, 3)];
            }
            $subtotal = 0;
            foreach ($items as $it) {
                $subtotal += DB::table('productos')->where('id', $it['producto_id'])->value('precio_clp') * $it['cantidad'];
            }
            $pct = mt_rand(1, 100) <= 10 ? 10 : 0;
            $total = $subtotal - (int) round($subtotal * $pct / 100);

            $r = mt_rand(1, 100);
            $pagos = match (true) {
                $r <= 50 => [['metodo' => 'efectivo', 'monto' => $total]],
                $r <= 78 => [['metodo' => 'debito', 'monto' => $total]],
                $r <= 88 => [['metodo' => 'credito', 'monto' => $total]],
                $r <= 93 => [['metodo' => 'transferencia', 'monto' => $total]],
                default => $total > 2000
                    ? [['metodo' => 'efectivo', 'monto' => 2000], ['metodo' => 'debito', 'monto' => $total - 2000]]
                    : [['metodo' => 'efectivo', 'monto' => $total]],
            };

            $cajera = $cajeras[mt_rand(0, count($cajeras) - 1)];
            try {
                $servicio->registrar($sede, $cajera, $items, $pagos, $pct, $turno, $cajera, $total);
            } catch (VentaRechazada) {
                // Sin stock suficiente: la venta se rechaza, igual que en la caja (RB-1).
            }
        }

        // Cierre a las 20:30 con arqueo: la mayoría cuadra; algunos tienen diferencia.
        Carbon::setTestNow($dia->copy()->setTime(20, 30)->utc());
        $porMetodo = DB::table('venta_pagos')->join('ventas', 'ventas.id', '=', 'venta_pagos.venta_id')
            ->where('ventas.turno_caja_id', $turnoId)->groupBy('venta_pagos.metodo')
            ->selectRaw('venta_pagos.metodo AS metodo, SUM(venta_pagos.monto_clp) AS suma')->pluck('suma', 'metodo');
        $espEf = 20000 + (int) ($porMetodo['efectivo'] ?? 0);
        $espTar = (int) ($porMetodo['debito'] ?? 0) + (int) ($porMetodo['credito'] ?? 0);
        $espTr = (int) ($porMetodo['transferencia'] ?? 0);
        $dif = mt_rand(1, 100) <= 72 ? 0 : [-2000, -1000, -500, -100, 200, 500][mt_rand(0, 5)];

        DB::table('turnos_caja')->where('id', $turnoId)->update([
            'estado' => 'cerrada', 'cerrada_por' => (mt_rand(0, 1) ? $cajeras[0] : $encargado)->id, 'cerrada_en' => now(),
            'esperado_efectivo_clp' => $espEf, 'esperado_tarjeta_clp' => $espTar, 'esperado_transferencia_clp' => $espTr,
            'real_efectivo_clp' => $espEf + $dif, 'real_tarjeta_clp' => $espTar, 'real_transferencia_clp' => $espTr,
            'descuadre_efectivo_clp' => $dif, 'updated_at' => now(),
        ]);
        Carbon::setTestNow();
    }

    private function ajuste(int $tenant, int $sede, int $producto, int $user, string $tipo, int $cantidad, string $motivo): void
    {
        $antes = (int) DB::table('stock_sede')->where('sede_id', $sede)->where('producto_id', $producto)->value('cantidad');
        $despues = match ($tipo) { 'entrada' => $antes + $cantidad, 'salida' => max(0, $antes - $cantidad), 'fijar' => $cantidad };
        DB::table('stock_sede')->where('sede_id', $sede)->where('producto_id', $producto)->update(['cantidad' => $despues, 'updated_at' => now()]);
        DB::table('ajustes_stock')->insert([
            'tenant_id' => $tenant, 'sede_id' => $sede, 'producto_id' => $producto, 'user_id' => $user, 'tipo' => $tipo,
            'cantidad_anterior' => $antes, 'cantidad_nueva' => $despues, 'diferencia' => $despues - $antes,
            'motivo' => $motivo, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
