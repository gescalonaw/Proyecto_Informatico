<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel de administración · ajustes manuales de stock (RF-4).
 * Cada ajuste guarda quién, cuándo, cuánto había, cuánto quedó y por qué (RNF-S-4, auditoría).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_stock', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->foreignId('sede_id')->constrained('sedes');
            $t->foreignId('producto_id')->constrained('productos');
            $t->foreignId('user_id')->constrained('users');
            $t->enum('tipo', ['entrada', 'salida', 'fijar']);
            $t->unsignedInteger('cantidad_anterior');
            $t->unsignedInteger('cantidad_nueva');
            $t->integer('diferencia');
            $t->string('motivo', 160);
            $t->timestamps();
            $t->index(['tenant_id', 'sede_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_stock');
    }
};
