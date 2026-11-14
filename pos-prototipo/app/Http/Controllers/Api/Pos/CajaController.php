<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\Sede;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Support\PosRespuesta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Caja compartida por sede (RF-5, RF-6). Equivale a CierreCaja.php del plugin:
 * una caja abierta por sede, varias cajeras venden sobre ella y al final se hace
 * un arqueo ciego: la cajera ingresa lo que contó sin ver lo esperado, y el
 * descuadre solo lo ven dueño/a y encargado/a (RB-10).
 */
class CajaController extends Controller
{
    private const ZONA = 'America/Santiago';

    /** GET /api/pos/v1/caja-abierta */
    public function abierta(Request $request)
    {
        $turno = TurnoCaja::abiertaEn($request->attributes->get('sede'));

        return PosRespuesta::ok(['caja' => $turno ? $this->formato($turno) : null]);
    }

    /** POST /api/pos/v1/apertura-caja {efectivo_inicial} */
    public function apertura(Request $request)
    {
        $efectivo = (int) round((float) $request->input('efectivo_inicial', 0));
        if ($efectivo < 0) {
            return PosRespuesta::error('El efectivo inicial no puede ser negativo.', 422);
        }

        /** @var Sede $sede */
        $sede = $request->attributes->get('sede');

        // Se bloquea la fila de la sede para que dos cajeras no abran dos cajas a la vez.
        $resultado = DB::transaction(function () use ($sede, $efectivo, $request) {
            Sede::whereKey($sede->id)->lockForUpdate()->first();
            if (TurnoCaja::abiertaEn($sede)) {
                return null;
            }

            return TurnoCaja::create([
                'tenant_id' => $sede->tenant_id,
                'sede_id' => $sede->id,
                'estado' => 'abierta',
                'abierta_por' => $request->user()->id,
                'abierta_en' => now(),
                'efectivo_inicial_clp' => $efectivo,
            ]);
        });

        if (! $resultado) {
            return PosRespuesta::error('Ya hay una caja abierta en esta sede.', 409);
        }

        return PosRespuesta::ok(['caja' => $this->formato($resultado)], 'Caja abierta.', 201);
    }

    /** POST /api/pos/v1/cierre-caja {efectivo_real, tarjeta_real?, transferencia_real?, cajera_id?} */
    public function cierre(Request $request)
    {
        $efectivoReal = $request->input('efectivo_real');
        if (! is_numeric($efectivoReal) || $efectivoReal < 0) {
            return PosRespuesta::error('Ingresa el efectivo contado.', 422);
        }
        $tarjetaReal = $request->input('tarjeta_real');
        $transfReal = $request->input('transferencia_real');
        foreach ([$tarjetaReal, $transfReal] as $x) {
            if ($x !== null && (! is_numeric($x) || $x < 0)) {
                return PosRespuesta::error('Los montos contados no son válidos.', 422);
            }
        }

        /** @var Sede $sede */
        $sede = $request->attributes->get('sede');
        $sesion = $request->user();

        $quien = $sesion;
        $cajeraId = (int) $request->input('cajera_id', 0);
        if ($cajeraId && $cajeraId !== $sesion->id) {
            $otra = User::where('tenant_id', $sesion->tenant_id)->find($cajeraId);
            if ($otra && $sede->permiteA($otra)) {
                $quien = $otra;
            }
        }

        $turno = DB::transaction(function () use ($sede, $efectivoReal, $tarjetaReal, $transfReal, $quien) {
            Sede::whereKey($sede->id)->lockForUpdate()->first();
            $turno = TurnoCaja::abiertaEn($sede);
            if (! $turno) {
                return null;
            }

            $porMetodo = DB::table('venta_pagos')
                ->join('ventas', 'ventas.id', '=', 'venta_pagos.venta_id')
                ->where('ventas.turno_caja_id', $turno->id)
                ->groupBy('venta_pagos.metodo')
                ->selectRaw('venta_pagos.metodo as metodo, SUM(venta_pagos.monto_clp) as suma')
                ->pluck('suma', 'metodo');

            $espEfectivo = $turno->efectivo_inicial_clp + (int) ($porMetodo['efectivo'] ?? 0);
            $espTarjeta = (int) ($porMetodo['debito'] ?? 0) + (int) ($porMetodo['credito'] ?? 0);
            $espTransf = (int) ($porMetodo['transferencia'] ?? 0);
            $realEfectivo = (int) round((float) $efectivoReal);

            $turno->update([
                'estado' => 'cerrada',
                'cerrada_por' => $quien->id,
                'cerrada_en' => now(),
                'esperado_efectivo_clp' => $espEfectivo,
                'esperado_tarjeta_clp' => $espTarjeta,
                'esperado_transferencia_clp' => $espTransf,
                'real_efectivo_clp' => $realEfectivo,
                'real_tarjeta_clp' => $tarjetaReal === null ? null : (int) round((float) $tarjetaReal),
                'real_transferencia_clp' => $transfReal === null ? null : (int) round((float) $transfReal),
                'descuadre_efectivo_clp' => $realEfectivo - $espEfectivo,
            ]);

            return $turno;
        });

        if (! $turno) {
            return PosRespuesta::error('No hay una caja abierta en esta sede.', 409);
        }

        $data = ['caja_id' => $turno->id];
        if (in_array($sesion->rol, ['dueno', 'encargado'], true)) {
            $data['arqueo'] = [
                'esperado_efectivo' => $turno->esperado_efectivo_clp,
                'real_efectivo' => $turno->real_efectivo_clp,
                'descuadre_efectivo' => $turno->descuadre_efectivo_clp,
                'esperado_tarjeta' => $turno->esperado_tarjeta_clp,
                'real_tarjeta' => $turno->real_tarjeta_clp,
                'esperado_transferencia' => $turno->esperado_transferencia_clp,
                'real_transferencia' => $turno->real_transferencia_clp,
            ];
        }

        return PosRespuesta::ok($data, 'Caja cerrada.');
    }

    /** GET /api/pos/v1/cajeras  → quiénes pueden atender en esta sede */
    public function cajeras(Request $request)
    {
        /** @var Sede $sede */
        $sede = $request->attributes->get('sede');

        $lista = User::where('tenant_id', $sede->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name', 'rol', 'tenant_id'])
            ->filter(fn (User $u) => $sede->permiteA($u))
            ->map(fn (User $u) => ['id' => $u->id, 'nombre' => $u->name, 'rol' => $u->rol])
            ->values();

        return PosRespuesta::ok(['cajeras' => $lista]);
    }

    /** POST /api/pos/v1/verificar-cajera {cajera_id, password}  → cambio de cajera activa */
    public function verificarCajera(Request $request)
    {
        /** @var Sede $sede */
        $sede = $request->attributes->get('sede');
        $id = (int) $request->input('cajera_id', 0);
        $password = (string) $request->input('password', '');

        $clave = 'cajera:' . $sede->id . ':' . $id . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($clave, 5)) {
            return PosRespuesta::error('Demasiados intentos; espera un minuto.', 429);
        }

        $cajera = User::where('tenant_id', $sede->tenant_id)->find($id);
        $ok = Hash::check($password, $cajera?->password ?? '$2y$12$HPv8y.NjnwQmGr8xAiJKJuoe1WnV8H8iFcmSUuWlhT/ivc3n8tApG');
        if (! $cajera || ! $ok || ! $sede->permiteA($cajera)) {
            RateLimiter::hit($clave, 60);
            return PosRespuesta::error('Contraseña incorrecta.', 401);
        }
        RateLimiter::clear($clave);

        return PosRespuesta::ok(['id' => $cajera->id, 'nombre' => $cajera->name]);
    }

    private function formato(TurnoCaja $t): array
    {
        $local = $t->abierta_en->copy()->setTimezone(self::ZONA);

        return [
            'id' => $t->id,
            'sede_id' => $t->sede_id,
            'fecha_apertura' => $local->format('Y-m-d H:i:s'),
            'fecha_apertura_local' => $local->format('d/m/Y H:i'),
            'efectivo_inicial' => (int) $t->efectivo_inicial_clp,
        ];
    }
}
