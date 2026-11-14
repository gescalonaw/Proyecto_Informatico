<?php

use App\Http\Controllers\Api\Pos\AuthController;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// php artisan poc:token                     -> token de 8 h para la cajera del Negocio 1
// php artisan poc:token cajera@negocio2.cl  -> token para otro usuario
Artisan::command('poc:token {email=cajera@negocio1.cl}', function (string $email) {
    $user = User::where('email', $email)->first();
    if (! $user) {
        $this->error("No existe el usuario $email (¿corriste php artisan migrate:fresh --seed?)");
        return 1;
    }
    $t = AuthController::emitirToken($user);
    $this->line($t['token']);
    return 0;
})->purpose('Genera un token POS para pruebas (PoC-2)');

// php artisan poc:stock 1 1 5   -> deja el producto 1 con stock 5 en la sede 1
// php artisan poc:stock 1 1     -> muestra el stock actual
Artisan::command('poc:stock {producto} {sede} {cantidad?}', function (int $producto, int $sede, ?int $cantidad = null) {
    $q = DB::table('stock_sede')->where('producto_id', $producto)->where('sede_id', $sede);
    if ($cantidad !== null) {
        $q->update(['cantidad' => $cantidad, 'updated_at' => now()]);
    }
    $this->line('Stock del producto ' . $producto . ' en la sede ' . $sede . ': ' . ($q->value('cantidad') ?? 'sin registro'));
    $this->line('Ventas registradas en total: ' . DB::table('ventas')->count());
})->purpose('Fija o muestra el stock de un producto en una sede');

// php artisan poc:caja 2          -> abre la caja de la sede 2 (si no hay una abierta)
// php artisan poc:caja 2 20000    -> ídem, con CLP 20.000 de efectivo inicial
// La venta exige una caja abierta (RB-3); la prueba de la última unidad la abre antes de empezar.
Artisan::command('poc:caja {sede} {efectivo=0}', function (int $sede, int $efectivo) {
    $s = DB::table('sedes')->find($sede);
    if (! $s) {
        $this->error("No existe la sede $sede");
        return 1;
    }
    $abierta = DB::table('turnos_caja')->where('sede_id', $sede)->where('estado', 'abierta')->first();
    if ($abierta) {
        $this->line("La sede $sede ({$s->nombre}) ya tiene la caja {$abierta->id} abierta.");
        return 0;
    }
    $quien = DB::table('users')->where('tenant_id', $s->tenant_id)->where('rol', 'dueno')->value('id');
    $id = DB::table('turnos_caja')->insertGetId([
        'tenant_id' => $s->tenant_id, 'sede_id' => $sede, 'estado' => 'abierta', 'abierta_por' => $quien,
        'abierta_en' => now(), 'efectivo_inicial_clp' => $efectivo, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->line("Caja $id abierta en la sede $sede ({$s->nombre}).");
    return 0;
})->purpose('Abre la caja de una sede para pruebas');
