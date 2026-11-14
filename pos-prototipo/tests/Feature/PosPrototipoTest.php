<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pruebas del núcleo de venta (corren en una base en memoria; no tocan tus datos).
 * php artisan test
 */
class PosPrototipoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function sedeDe(int $negocio, string $nombre = 'Tienda Centro'): int
    {
        return (int) DB::table('sedes')->join('tenants', 'tenants.id', '=', 'sedes.tenant_id')
            ->where('tenants.nombre', "Negocio $negocio")->where('sedes.nombre', $nombre)->value('sedes.id');
    }

    private function token(string $email = 'cajera@negocio1.cl', ?int $sede = null): string
    {
        $r = $this->postJson('/api/pos/v1/login', [
            'user_login' => $email, 'password' => 'secreto123', 'ubicacion_id' => $sede ?? $this->sedeDe(1),
        ]);
        $r->assertOk()->assertJsonPath('success', true);

        return $r->json('data.token');
    }

    private function comoCajera(?int $sede = null, string $email = 'cajera@negocio1.cl'): self
    {
        $sede ??= $this->sedeDe(1);

        return $this->withToken($this->token($email, $sede))->withHeader('X-POS-Location-Id', (string) $sede);
    }

    private function abrirCaja(int $sede): void
    {
        $this->artisan('poc:caja', ['sede' => $sede])->assertSuccessful();
    }

    public function test_login_incorrecto_devuelve_401(): void
    {
        $this->postJson('/api/pos/v1/login', ['user_login' => 'cajera@negocio1.cl', 'password' => 'mala'])->assertStatus(401);
        $this->postJson('/api/pos/v1/login', ['user_login' => 'nadie@x.cl', 'password' => 'mala'])->assertStatus(401);
    }

    public function test_login_acepta_el_formato_de_la_poc(): void
    {
        $this->postJson('/api/pos/v1/login', ['email' => 'cajera@negocio1.cl', 'password' => 'secreto123'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'token_expiry', 'user_id', 'rol', 'nombre_usuario']]);
    }

    public function test_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/pos/v1/productos?buscar=7801000000001')->assertStatus(401)->assertJsonPath('success', false);
    }

    public function test_busca_producto_por_codigo_de_barras(): void
    {
        $r = $this->comoCajera()->getJson('/api/pos/v1/productos?codigo_barras=7801000000001');
        $r->assertOk()->assertJsonPath('data.productos.0.codigo_barras', '7801000000001')
            ->assertJsonPath('data.productos.0.stock_disponible', 1000);
        $this->assertArrayNotHasKey('costo_clp', $r->json('data.productos.0')); // RB-10
    }

    public function test_busqueda_por_texto_y_paginacion(): void
    {
        $r = $this->comoCajera()->getJson('/api/pos/v1/productos?search=Arroz&limit=10&offset=0');
        $r->assertOk();
        $this->assertSame(25, $r->json('data.total')); // 1.000 / 40 nombres
        $this->assertCount(10, $r->json('data.productos'));
    }

    public function test_ruta_de_la_poc2_sigue_respondiendo(): void
    {
        $this->comoCajera()->getJson('/api/pos/v1/productos?buscar=7801000000001')
            ->assertOk()->assertJsonPath('data.productos.0.codigo_barras', '7801000000001');
    }

    public function test_aislamiento_un_negocio_no_ve_productos_de_otro(): void
    {
        $this->comoCajera()->getJson('/api/pos/v1/productos?search=7802000000001')
            ->assertOk()->assertJsonPath('data.total', 0);
        $otro = DB::table('productos')->where('codigo_barras', '7802000000001')->value('id');
        $this->comoCajera()->getJson("/api/pos/v1/producto/$otro")->assertStatus(404);
    }

    public function test_aislamiento_no_puede_usar_una_sede_de_otro_negocio(): void
    {
        $this->withToken($this->token())->withHeader('X-POS-Location-Id', (string) $this->sedeDe(2))
            ->getJson('/api/pos/v1/productos?buscar=7801000000001')->assertStatus(403);
    }

    public function test_venta_con_pago_mixto_descuenta_stock(): void
    {
        $sede = $this->sedeDe(1);
        $this->abrirCaja($sede);
        $p = DB::table('productos')->where('codigo_barras', '7801000000001')->first();
        $total = $p->precio_clp * 2;

        $this->comoCajera($sede)->postJson('/api/pos/v1/venta', [
                'items' => [['producto_id' => $p->id, 'cantidad' => 2]],
                'pagos' => [['metodo' => 'efectivo', 'monto' => 1000], ['metodo' => 'debito', 'monto' => $total - 1000]],
            ])->assertCreated()->assertJsonPath('success', true)->assertJsonPath('data.total', $total);

        $this->assertSame(998, (int) DB::table('stock_sede')->where('producto_id', $p->id)->where('sede_id', $sede)->value('cantidad'));
    }

    public function test_venta_que_dejaria_stock_negativo_se_rechaza(): void
    {
        $sede = $this->sedeDe(1);
        $this->abrirCaja($sede);
        $p = DB::table('productos')->where('codigo_barras', '7801000000001')->first();
        DB::table('stock_sede')->where('producto_id', $p->id)->where('sede_id', $sede)->update(['cantidad' => 1]);

        $this->comoCajera($sede)->postJson('/api/pos/v1/venta', [
                'items' => [['producto_id' => $p->id, 'cantidad' => 2]],
                'pagos' => [['metodo' => 'efectivo', 'monto' => $p->precio_clp * 2]],
            ])->assertStatus(409)->assertJsonPath('success', false);

        $this->assertSame(1, (int) DB::table('stock_sede')->where('producto_id', $p->id)->where('sede_id', $sede)->value('cantidad'));
        $this->assertSame(0, DB::table('ventas')->count());
    }

    public function test_pagos_que_no_suman_el_total_se_rechazan(): void
    {
        $sede = $this->sedeDe(1);
        $this->abrirCaja($sede);
        $p = DB::table('productos')->where('codigo_barras', '7801000000001')->first();

        $this->comoCajera($sede)->postJson('/api/pos/v1/venta', [
                'items' => [['producto_id' => $p->id, 'cantidad' => 1]],
                'pagos' => [['metodo' => 'efectivo', 'monto' => $p->precio_clp - 1]],
            ])->assertStatus(409);
    }
}
