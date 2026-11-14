<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel de administración: solo dueño/a y encargado/a (RNF-S-2).
 * Fija el negocio de la sesión para que toda consulta quede aislada (RNF-S-6).
 */
class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::set(null);
        $user = Auth::user();

        if (! $user) {
            return redirect()->guest(route('admin.login'));
        }
        if (! $user->tenant_id || ! in_array($user->rol, ['dueno', 'encargado'], true)) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('admin.login')->withErrors(['email' => 'Tu usuario no tiene acceso al panel.']);
        }

        TenantContext::set((int) $user->tenant_id);
        view()->share('usuarioPanel', $user);
        view()->share('negocioPanel', \App\Models\Tenant::find($user->tenant_id));

        return $next($request);
    }
}
