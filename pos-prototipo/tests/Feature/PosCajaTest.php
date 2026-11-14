<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * E3 · Pantalla POS reciclada + caja compartida por sede.
 * Cubre las rutas que usa la pantalla del plugin y las reglas RB-3 (sin caja no se vende),
 * caja compartida (una por sede, varias cajeras), arqueo ciego (RB-10) y aislamiento (RNF-S-6).
 */
class PosCajaTest extends TestCase
{
    use RefreshDatabase;

    private int $centro;
    private int $mall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->centro = $this->sede(1, 'Tienda Centro');
        $this->mall = $this->sede(1, 'Tienda Mall');
    }

    private function sede(int $negocio, string $nombre): int
    {
        return (int) DB::table('sedes')->join('tenants', 'tenants.id', '=', 'sedes.tenant_id')
            ->where('tenants.nombre', "Negocio $negocio")->where('sedes.nombre', $nombre)->value('sedes.id');
    }

    private function login(string $email, int $sede): array
    {
        return $this->postJson('/api/pos/v1/login', ['user_login' => $email, 'password' => 'secreto123', 'ubicacion_id' => $sede])
            ->assertOk()->json('data');
    }

    private function como(string $email, int $sede): self
    {
        $d = $this->login($email, $sede);

        return $this->withToken($d['token'])->withHeader('X-POS-Location-Id', (string) $sede);
    }

    private function producto(string $codigo = '7801000000001'): object
    {
        return DB::table('productos')->where('codigo_barras', $codigo)->first();
    }

    private function vender(string $email, int $sede, array $pagos, int $cantidad = 1, float $desc = 0, ?int $cajeraId = null)
    {
        $p = $this->producto();

        return $this->como($email, $sede)->postJson('/api/pos/v1/venta', array_filter([
            'ubicacion_id' => $sede,
            'items' => [['producto_id' => $p->id, 'cantidad' => $cantidad, 'precio_unitario' => $p->precio_clp]],
            'pagos' => $pagos,
            'descuento_pct' => $desc,
            'cajera_id' => $cajeraId,
        ], fn ($v) => $v !== null));
    }

    // ── Login y sedes ──────────────────────────────────────────

    public function test_ubicaciones_exige_negocio_y_solo_lista_sus_tiendas(): void
    {
        $this->getJson('/api/pos/v1/ubicaciones')->assertStatus(404);
        $r = $this->getJson('/api/pos/v1/ubicaciones?negocio=negocio-1')->assertOk();
        $nombres = collect($r->json('data.ubicaciones'))->pluck('nombre')->all();
        $this->assertSame(['Tienda Centro', 'Tienda Mall'], $nombres); // sin Bodega ni sedes del negocio 2
    }

    public function test_login_con_sede_de_otro_negocio_se_rechaza(): void
    {
        $this->postJson('/api/pos/v1/login', ['user_login' => 'cajera@negocio1.cl', 'password' => 'secreto123',
            'ubicacion_id' => $this->sede(2, 'Tienda Centro')])->assertStatus(403);
    }

    public function test_cajera_no_asignada_no_entra_a_la_sede(): void
    {
        // cajera2 solo está asignada a Tienda Centro.
        $this->postJson('/api/pos/v1/login', ['user_login' => 'cajera2@negocio1.cl', 'password' => 'secreto123',
            'ubicacion_id' => $this->mall])->assertStatus(403);
        $this->login('cajera2@negocio1.cl', $this->centro);
    }

    public function test_cabecera_de_usuario_falsificada_no_cambia_el_usuario(): void
    {
        $dueno = DB::table('users')->where('email', 'dueno@negocio1.cl')->value('id');
        $this->como('cajera@negocio1.cl', $this->centro)->withHeader('X-POS-User-Id', (string) $dueno)
            ->postJson('/api/pos/v1/apertura-caja', ['efectivo_inicial' => 0])->assertCreated();
        $this->assertSame(
            DB::table('users')->where('email', 'cajera@negocio1.cl')->value('id'),
            DB::table('turnos_caja')->value('abierta_por')
        );
    }

    // ── Caja compartida ────────────────────────────────────────

    public function test_sin_caja_abierta_no_se_vende(): void
    {
        $this->vender('cajera@negocio1.cl', $this->centro, [['metodo' => 'efectivo', 'monto' => $this->producto()->precio_clp]])
            ->assertStatus(409);
        $this->assertSame(0, DB::table('ventas')->count());
    }

    public function test_una_sola_caja_abierta_por_sede(): void
    {
        $this->como('cajera@negocio1.cl', $this->centro)->getJson('/api/pos/v1/caja-abierta')->assertOk()->assertJsonPath('data.caja', null);
        $this->como('cajera@negocio1.cl', $this->centro)->postJson('/api/pos/v1/apertura-caja', ['efectivo_inicial' => 20000])->assertCreated();
        $this->como('cajera2@negocio1.cl', $this->centro)->postJson('/api/pos/v1/apertura-caja', ['efectivo_inicial' => 5000])->assertStatus(409);
        // La otra cajera ve la misma caja abierta (compartida).
        $this->como('cajera2@negocio1.cl', $this->centro)->getJson('/api/pos/v1/caja-abierta')
            ->assertOk()->assertJsonPath('data.caja.efectivo_inicial', 20000);
        // Otra sede del mismo negocio sigue sin caja.
        $this->como('cajera@negocio1.cl', $this->mall)->getJson('/api/pos/v1/caja-abierta')->assertJsonPath('data.caja', null);
    }

    public function test_cierre_calcula_el_descuadre_y_la_cajera_no_lo_ve(): void
    {
        $precio = $this->producto()->precio_clp; // 1.000
        $this->como('cajera@negocio1.cl', $this->centro)->postJson('/api/pos/v1/apertura-caja', ['efectivo_inicial' => 20000])->assertCreated();

        // Dos cajeras venden sobre la misma caja: una con efectivo y otra con pago mixto.
        $this->vender('cajera@negocio1.cl', $this->centro, [['metodo' => 'efectivo', 'monto' => $precio * 2]], 2)->assertCreated();
        $this->vender('cajera2@negocio1.cl', $this->centro, [['metodo' => 'efectivo', 'monto' => 500], ['metodo' => 'debito', 'monto' => $precio - 500]])->assertCreated();

        // Esperado en efectivo: 20.000 + 2.000 + 500 = 22.500. Se cuentan 22.000 → faltan 500.
        $r = $this->como('cajera@negocio1.cl', $this->centro)->postJson('/api/pos/v1/cierre-caja', [
            'efectivo_real' => 22000, 'tarjeta_real' => $precio - 500, 'transferencia_real' => null,
        ])->assertOk()->assertJsonPath('success', true);
        $this->assertArrayNotHasKey('arqueo', $r->json('data')); // arqueo ciego (RB-10)

        $t = DB::table('turnos_caja')->first();
        $this->assertSame('cerrada', $t->estado);
        $this->assertSame(22500, (int) $t->esperado_efectivo_clp);
        $this->assertSame(-500, (int) $t->descuadre_efectivo_clp);
        $this->assertSame($precio - 500, (int) $t->esperado_tarjeta_clp);

        // Después del cierre ya no se puede vender hasta abrir otra caja.
        $this->vender('cajera@negocio1.cl', $this->centro, [['metodo' => 'efectivo', 'monto' => $precio]])->assertStatus(409);
    }

    public function test_el_encargado_si_ve_el_descuadre(): void
    {
        $this->como('encargado@negocio1.cl', $this->centro)->postJson('/api/pos/v1/apertura-caja', ['efectivo_inicial' => 10000]);
        $this->como('encargado@negocio1.cl', $this->centro)->postJson('/api/pos/v1/cierre-caja', ['efectivo_real' => 10200])
            ->assertOk()->assertJsonPath('data.arqueo.descuadre_efectivo', 200);
    }

    // ── Venta desde la pantalla del plugin ─────────────────────

    public function test_venta_con_descuento_igual_que_la_pantalla(): void
    {
        $precio = $this->producto()->precio_clp; // 1.000 × 3 = 3.000; 15 % = 450 → total 2.550
        $this->artisan('poc:caja', ['sede' => $this->centro]);
        $this->vender('cajera@negocio1.cl', $this->centro, [['metodo' => 'efectivo', 'monto' => 2550]], 3, 15)
            ->assertCreated()->assertJsonPath('data.descuento', 450)->assertJsonPath('data.total', 2550);
        $this->assertSame(3 * $precio, (int) DB::table('ventas')->value('subtotal_clp'));
    }

    public function test_el_precio_lo_pone_el_servidor_no_el_navegador(): void
    {
        $this->artisan('poc:caja', ['sede' => $this->centro]);
        $p = $this->producto();
        $this->como('cajera@negocio1.cl', $this->centro)->postJson('/api/pos/v1/venta', [
            'items' => [['producto_id' => $p->id, 'cantidad' => 1, 'precio_unitario' => 1]],
            'pagos' => [['metodo' => 'efectivo', 'monto' => 1]],
            'total' => 1,
        ])->assertStatus(409);
    }

    public function test_la_venta_se_atribuye_a_la_cajera_activa(): void
    {
        $this->artisan('poc:caja', ['sede' => $this->centro]);
        $bea = (int) DB::table('users')->where('email', 'cajera2@negocio1.cl')->value('id');
        $this->vender('cajera@negocio1.cl', $this->centro, [['metodo' => 'efectivo', 'monto' => $this->producto()->precio_clp]], 1, 0, $bea)
            ->assertCreated();
        $this->assertSame($bea, (int) DB::table('ventas')->value('usuario_id'));
    }

    public function test_cajera_activa_de_otro_negocio_se_rechaza(): void
    {
        $this->artisan('poc:caja', ['sede' => $this->centro]);
        $ajena = (int) DB::table('users')->where('email', 'cajera@negocio2.cl')->value('id');
        $this->vender('cajera@negocio1.cl', $this->centro, [['metodo' => 'efectivo', 'monto' => $this->producto()->precio_clp]], 1, 0, $ajena)
            ->assertStatus(403);
    }

    public function test_cajeras_de_la_sede_y_cambio_con_contrasena(): void
    {
        $r = $this->como('cajera@negocio1.cl', $this->mall)->getJson('/api/pos/v1/cajeras')->assertOk();
        $nombres = collect($r->json('data.cajeras'))->pluck('nombre');
        $this->assertTrue($nombres->contains('Cajera Ana 1'));
        $this->assertFalse($nombres->contains('Cajera Bea 1')); // no asignada al Mall

        $bea = (int) DB::table('users')->where('email', 'cajera2@negocio1.cl')->value('id');
        $this->como('cajera@negocio1.cl', $this->centro)->postJson('/api/pos/v1/verificar-cajera', ['cajera_id' => $bea, 'password' => 'mala'])->assertStatus(401);
        $this->como('cajera@negocio1.cl', $this->centro)->postJson('/api/pos/v1/verificar-cajera', ['cajera_id' => $bea, 'password' => 'secreto123'])
            ->assertOk()->assertJsonPath('data.nombre', 'Cajera Bea 1');
    }

    public function test_la_pantalla_pos_carga_por_negocio(): void
    {
        $this->get('/pos/negocio-1')->assertOk()->assertSee('POS_CONFIG', false)->assertSee('negocio-1');
        $this->get('/pos/no-existe')->assertNotFound();
    }
}
