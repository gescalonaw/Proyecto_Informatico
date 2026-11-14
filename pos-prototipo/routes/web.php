<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

// Pantalla POS reciclada del plugin. Cada negocio entra por su URL: /pos/negocio-1
Route::get('/', fn () => redirect('/pos/negocio-1'));

Route::get('/pos/{negocio}', function (string $negocio) {
    $tenant = Tenant::where('slug', $negocio)->where('estado', 'activo')->firstOrFail();

    return view('pos', ['tenant' => $tenant]);
})->where('negocio', '[a-z0-9\-]+');

// ── Panel de administración (dueño/a y encargado/a) ──────────────
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [\App\Http\Controllers\Admin\LoginController::class, 'form'])->name('login');
    Route::post('login', [\App\Http\Controllers\Admin\LoginController::class, 'login'])->name('login.post');
    Route::post('salir', [\App\Http\Controllers\Admin\LoginController::class, 'logout'])->name('logout');

    Route::middleware('pos.admin')->group(function () {
        Route::get('/', \App\Http\Controllers\Admin\DashboardController::class)->name('dashboard');
        Route::get('cierres', [\App\Http\Controllers\Admin\CierreController::class, 'index'])->name('cierres');
        Route::get('cierres/{id}', [\App\Http\Controllers\Admin\CierreController::class, 'show'])->whereNumber('id')->name('cierres.show');
        Route::get('inventario', [\App\Http\Controllers\Admin\InventarioController::class, 'index'])->name('inventario');
        Route::post('inventario/ajustar', [\App\Http\Controllers\Admin\InventarioController::class, 'ajustar'])->name('inventario.ajustar');
    });
});
