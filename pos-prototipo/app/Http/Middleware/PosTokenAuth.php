<?php

namespace App\Http\Middleware;

use App\Models\Sede;
use App\Models\User;
use App\Support\PosRespuesta;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Equivale a check_token_permission() del plugin:
 *   Authorization: Bearer {token}   y   X-POS-Location-Id: {sede}
 * El token se guarda solo como hash SHA-256 y vence a las 8 horas.
 * El usuario sale SIEMPRE del token: la cabecera X-POS-User-Id que manda la pantalla
 * del plugin se ignora, porque el cliente podría falsificarla.
 */
class PosTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::set(null);

        $token = $request->bearerToken();
        if (! $token) {
            return PosRespuesta::error('Falta el token de sesión.', 401);
        }

        $fila = DB::table('pos_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->where('expira_en', '>', now())
            ->first();
        if (! $fila) {
            return PosRespuesta::error('Sesión vencida. Inicia sesión nuevamente.', 401);
        }

        $user = User::find($fila->user_id);
        if (! $user || ! $user->tenant_id) {
            return PosRespuesta::error('Usuario sin negocio.', 403);
        }

        TenantContext::set((int) $user->tenant_id);
        Auth::setUser($user);

        $sedeId = (int) ($request->header('X-POS-Location-Id') ?: $request->input('ubicacion_id'));
        $sede = Sede::find($sedeId); // el scope de negocio ya filtra: otra empresa = null
        if (! $sede || ! $sede->permiteA($user)) {
            return PosRespuesta::error('Sin acceso a esta sede.', 403);
        }
        $request->attributes->set('sede', $sede);

        return $next($request);
    }
}
