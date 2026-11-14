<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Núcleo del prototipo (iteración 1 de la guía de migración).
 * Viene de includes/Database.php del plugin, con tenant_id en todas las tablas,
 * productos propios (sin WooCommerce) y montos en enteros CLP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 120)->unique();
            $t->enum('estado', ['activo', 'suspendido'])->default('activo');
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            // Sin restricción de clave foránea aquí: SQLite no siempre permite agregarla a una tabla existente.
            $t->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
            $t->string('rol', 20)->default('cajera')->after('email'); // dueno | encargado | cajera
        });

        Schema::create('sedes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->string('nombre', 100);
            $t->enum('tipo', ['bodega', 'tienda'])->default('tienda');
            $t->timestamps();
            $t->unique(['tenant_id', 'nombre']);
        });

        Schema::create('productos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->string('sku', 40);
            $t->string('codigo_barras', 40)->nullable();
            $t->string('nombre', 160);
            $t->unsignedBigInteger('precio_clp');
            $t->unsignedBigInteger('costo_clp')->default(0);
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'sku']);
            $t->unique(['tenant_id', 'codigo_barras']);
        });

        Schema::create('stock_sede', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->foreignId('producto_id')->constrained('productos');
            $t->foreignId('sede_id')->constrained('sedes');
            $t->unsignedInteger('cantidad')->default(0); // unsigned: la base también impide stock < 0
            $t->timestamps();
            $t->unique(['producto_id', 'sede_id']);
        });

        Schema::create('ventas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->foreignId('sede_id')->constrained('sedes');
            $t->foreignId('usuario_id')->constrained('users');
            $t->string('referencia', 40)->unique();
            $t->unsignedBigInteger('total_clp');
            $t->timestamps();
            $t->index(['tenant_id', 'sede_id', 'created_at']);
        });

        Schema::create('venta_lineas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('venta_id')->constrained('ventas');
            $t->foreignId('producto_id')->constrained('productos');
            $t->unsignedInteger('cantidad');
            $t->unsignedBigInteger('precio_unitario_clp');
        });

        Schema::create('venta_pagos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('venta_id')->constrained('ventas');
            $t->enum('metodo', ['efectivo', 'debito', 'credito', 'transferencia']);
            $t->unsignedBigInteger('monto_clp');
        });

        Schema::create('pos_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expira_en');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['pos_tokens', 'venta_pagos', 'venta_lineas', 'ventas', 'stock_sede', 'productos', 'sedes'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['tenant_id']);
            $t->dropColumn(['tenant_id', 'rol']);
        });
        Schema::dropIfExists('tenants');
    }
};
