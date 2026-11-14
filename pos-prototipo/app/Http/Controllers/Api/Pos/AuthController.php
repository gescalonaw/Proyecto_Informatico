<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\Sede;
use App\Models\User;
use App\Support\PosRespuesta;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /** Hash de relleno: siempre se verifica una clave, exista o no el usuario (defensa de tiempo del plugin). */
    private const HASH_RELLENO = '$2y$12$HPv8y.NjnwQmGr8xAiJKJuoe1WnV8H8iFcmSUuWlhT/ivc3n8tApG';

    /**
     * POST /api/pos/v1/login
     * Pantalla del plugin: {user_login, password, ubicacion_id}.  Scripts de la PoC: {email, password}.
     * Respuesta: {success, data:{token, token_expiry, user_id, ubicacion_id, rol, nombre_usuario}}
     */
    public function login(Request $request)
    {
        TenantContext::set(null);

        $email = Str::lower(trim((string) ($request->input('user_login') ?? $request->input('email') ?? '')));
        $password = (string) $request->input('password', '');
        $sedeId = (int) $request->input('ubicacion_id', 0);

        if ($email === '' || $password === '') {
            return PosRespuesta::error('Usuario y contraseña son requeridos.', 422);
        }

        $clave = 'login:' . $email . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($clave, 5)) {
            return PosRespuesta::error('Demasiados intentos; espera un minuto.', 429);
        }

        $user = User::where('email', $email)->first();
        $ok = Hash::check($password, $user?->password ?? self::HASH_RELLENO);
        if (! $user || ! $ok) {
            RateLimiter::hit($clave, 60);
            return PosRespuesta::error('Credenciales incorrectas.', 401);
        }

        $sede = null;
        if ($sedeId) {
            $sede = Sede::find($sedeId);
            if (! $sede || ! $sede->permiteA($user)) {
                RateLimiter::hit($clave, 60);
                return PosRespuesta::error('No tienes acceso a esta sede.', 403);
            }
        }
        RateLimiter::clear($clave);

        $t = self::emitirToken($user);

        return PosRespuesta::ok([
            'token' => $t['token'],
            'token_expiry' => $t['expira_ts'],
            'user_id' => $user->id,
            'ubicacion_id' => $sede?->id,
            'rol' => $user->rol,
            'nombre_usuario' => $user->name,
        ], 'Sesión iniciada correctamente.');
    }

    /** Crea un token opaco de 8 h y devuelve el texto plano una sola vez (en la BD queda solo el hash). */
    public static function emitirToken(User $user): array
    {
        $token = Str::random(48); // solo letras y números (lección del plugin)
        $expira = now()->addHours(8);
        DB::table('pos_tokens')->insert([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expira_en' => $expira,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['token' => $token, 'expira_en' => $expira->toIso8601String(), 'expira_ts' => $expira->getTimestamp()];
    }
}
