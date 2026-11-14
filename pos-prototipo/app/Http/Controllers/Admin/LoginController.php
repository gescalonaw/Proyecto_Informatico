<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public function form()
    {
        return Auth::check() && in_array(Auth::user()->rol, ['dueno', 'encargado'], true)
            ? redirect()->route('admin.dashboard')
            : view('admin.login');
    }

    public function login(Request $request)
    {
        $datos = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $clave = 'admin:' . Str::lower($datos['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($clave, 5)) {
            return back()->withErrors(['email' => 'Demasiados intentos; espera un minuto.'])->onlyInput('email');
        }
        if (! Auth::attempt($datos)) {
            RateLimiter::hit($clave, 60);
            return back()->withErrors(['email' => 'Credenciales incorrectas.'])->onlyInput('email');
        }
        if (! in_array(Auth::user()->rol, ['dueno', 'encargado'], true)) {
            Auth::logout();
            return back()->withErrors(['email' => 'El panel es solo para dueño/a y encargado/a. Las cajeras usan la pantalla POS.'])->onlyInput('email');
        }

        RateLimiter::clear($clave);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
