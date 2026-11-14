<?php

use App\Http\Controllers\Api\Pos\AuthController;
use App\Http\Controllers\Api\Pos\CajaController;
use App\Http\Controllers\Api\Pos\ProductoController;
use App\Http\Controllers\Api\Pos\UbicacionController;
use App\Http\Controllers\Api\Pos\VentaController;
use Illuminate\Support\Facades\Route;

// Mismo contrato que el plugin (/wp-json/pos/v1/...), ahora bajo /api/pos/v1/...
// La pantalla POS del plugin (public/pos/js) usa estas rutas sin cambios.
Route::prefix('pos/v1')->group(function () {
    Route::get('ubicaciones', [UbicacionController::class, 'index']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('pos.auth')->group(function () {
        Route::get('productos', [ProductoController::class, 'index']);
        Route::get('producto/{id}', [ProductoController::class, 'show'])->whereNumber('id');
        Route::post('venta', [VentaController::class, 'store']);

        Route::get('caja-abierta', [CajaController::class, 'abierta']);
        Route::post('apertura-caja', [CajaController::class, 'apertura']);
        Route::post('cierre-caja', [CajaController::class, 'cierre']);
        Route::get('cajeras', [CajaController::class, 'cajeras']);
        Route::post('verificar-cajera', [CajaController::class, 'verificarCajera']);
    });
});
