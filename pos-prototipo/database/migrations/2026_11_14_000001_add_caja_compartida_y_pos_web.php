<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E3 · Pantalla POS reciclada del plugin + caja compartida por sede (RF-5, RF-6, RB-3).
 *
 * - tenants.slug: cada negocio entra por su propia URL (/pos/{slug}); así la lista de
 *   sedes del login ya no expone las sedes de todos los negocios (el plugin era de un solo negocio).
 * - sedes.codigo: código corto que muestra el selector de sede del login.
 * - sede_user: qué cajeras pueden trabajar en qué sede (dueño/a y encargado/a ven todas).
 * - turnos_caja: una caja abierta por sede, compartida por las cajeras; arqueo ciego al cierre.
 * - ventas: turno al que pertenece, subtotal, descuento y quién inició la sesión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('slug', 60)->nullable()->unique();
        });

        Schema::table('sedes', function (Blueprint $t) {
            $t->string('codigo', 20)->nullable();
        });

        Schema::create('sede_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->foreignId('sede_id')->constrained('sedes');
            $t->foreignId('user_id')->constrained('users');
            $t->timestamps();
            $t->unique(['sede_id', 'user_id']);
        });

        Schema::create('turnos_caja', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->foreignId('sede_id')->constrained('sedes');
            $t->enum('estado', ['abierta', 'cerrada'])->default('abierta');
            $t->foreignId('abierta_por')->constrained('users');
            $t->timestamp('abierta_en');
            $t->unsignedBigInteger('efectivo_inicial_clp')->default(0);
            $t->foreignId('cerrada_por')->nullable()->constrained('users');
            $t->timestamp('cerrada_en')->nullable();
            // Lo que el sistema espera según las ventas del turno.
            $t->unsignedBigInteger('esperado_efectivo_clp')->nullable();
            $t->unsignedBigInteger('esperado_tarjeta_clp')->nullable();
            $t->unsignedBigInteger('esperado_transferencia_clp')->nullable();
            // Lo que se contó (arqueo ciego: la cajera no ve lo esperado).
            $t->unsignedBigInteger('real_efectivo_clp')->nullable();
            $t->unsignedBigInteger('real_tarjeta_clp')->nullable();
            $t->unsignedBigInteger('real_transferencia_clp')->nullable();
            $t->bigInteger('descuadre_efectivo_clp')->nullable(); // real − esperado (puede ser negativo)
            $t->timestamps();
            $t->index(['tenant_id', 'sede_id', 'estado']);
        });

        Schema::table('ventas', function (Blueprint $t) {
            $t->unsignedBigInteger('turno_caja_id')->nullable()->index();
            $t->unsignedBigInteger('subtotal_clp')->default(0);
            $t->unsignedBigInteger('descuento_clp')->default(0);
            $t->unsignedBigInteger('registrada_por')->nullable(); // titular de la sesión (la venta va a la cajera activa)
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $t) {
            $t->dropIndex(['turno_caja_id']);
            $t->dropColumn(['turno_caja_id', 'subtotal_clp', 'descuento_clp', 'registrada_por']);
        });
        Schema::dropIfExists('turnos_caja');
        Schema::dropIfExists('sede_user');
        Schema::table('sedes', fn (Blueprint $t) => $t->dropColumn('codigo'));
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropUnique(['slug']);
            $t->dropColumn('slug');
        });
    }
};
