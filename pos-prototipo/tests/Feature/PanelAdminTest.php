<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\HistoriaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Panel de administración: acceso por rol, aislamiento por negocio,
 * dashboard, cierres de caja e inventario con ajuste de stock.
 */
class PanelAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function usuario(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function sede(int $negocio, string $codigo): int
    {
        return (int) DB::table('sedes')->join('tenants', 'tenants.id', '=', 'sedes.tenant_id')
            ->where('tenants.slug', "negocio-$negocio")->where('sedes.codigo', $codigo)->value('sedes.id');
    }

    // ── Acceso ─────────────────────────────────────────────────

    public function test_sin_sesion_redirige_al_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Panel de administración');
    }

    public function test_login_de_la_duena_y_rechazo_de_la_cajera(): void
    {
        $this->post('/admin/login', ['email' => 'dueno@negocio1.cl', 'password' => 'secreto123'])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSee('Ventas');
        $this->post('/admin/salir');

        $this->post('/admin/login', ['email' => 'cajera@negocio1.cl', 'password' => 'secreto123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_cajera_con_sesion_no_entra_al_panel(): void
    {
        $this->actingAs($this->usuario('cajera@negocio1.cl'))->get('/admin/cierres')->assertRedirect('/admin/login');
    }

    // ── Dashboard ──────────────────────────────────────────────

    public function test_dashboard_suma_solo_las_ventas_del_negocio(): void
    {
        $this->seed(HistoriaSeeder::class); // 14 días de ventas del Negocio 1
        $total = (int) DB::table('ventas')->where('tenant_id', DB::table('tenants')->where('slug', 'negocio-1')->value('id'))
            ->where('created_at', '>=', now('America/Santiago')->subDays(29)->startOfDay()->utc())->sum('total_clp');
        $this->assertGreaterThan(0, $total);

        $this->actingAs($this->usuario('encargado@negocio1.cl'))->get('/admin?periodo=30')
            ->assertOk()->assertSee(\App\Support\Fmt::clp($total));

        // El Negocio 2 no tiene ventas: su dueña ve $0.
        $this->actingAs($this->usuario('dueno@negocio2.cl'))->get('/admin?periodo=30')
            ->assertOk()->assertSee('Sin ventas en el periodo.')->assertDontSee(\App\Support\Fmt::clp($total));
    }

    // ── Cierres ────────────────────────────────────────────────

    public function test_cierres_muestra_descuadre_y_detalle(): void
    {
        $this->seed(HistoriaSeeder::class);
        $conDescuadre = DB::table('turnos_caja')->where('descuadre_efectivo_clp', '<', 0)->first();
        $this->assertNotNull($conDescuadre);

        $this->actingAs($this->usuario('dueno@negocio1.cl'))->get('/admin/cierres?periodo=30&descuadre=1')
            ->assertOk()->assertSee('Falta ');
        $this->get("/admin/cierres/{$conDescuadre->id}")
            ->assertOk()->assertSee('Caja #' . $conDescuadre->id)->assertSee('Falta dinero');
    }

    public function test_no_se_ve_el_cierre_de_otro_negocio(): void
    {
        $this->seed(HistoriaSeeder::class);
        $id = DB::table('turnos_caja')->value('id'); // del Negocio 1
        $this->actingAs($this->usuario('dueno@negocio2.cl'))->get("/admin/cierres/$id")->assertNotFound();
    }

    // ── Inventario ─────────────────────────────────────────────

    public function test_inventario_y_costo_solo_para_la_duena(): void
    {
        $centro = $this->sede(1, 'CEN');
        $this->actingAs($this->usuario('dueno@negocio1.cl'))->get("/admin/inventario?sede=$centro&q=Arroz")
            ->assertOk()->assertSee('Arroz grado 1 1 kg')->assertSee('Margen');
        $this->actingAs($this->usuario('encargado@negocio1.cl'))->get("/admin/inventario?sede=$centro")
            ->assertOk()->assertDontSee('Margen');
    }

    public function test_ajuste_de_stock_queda_registrado(): void
    {
        $centro = $this->sede(1, 'CEN');
        $p = DB::table('productos')->where('codigo_barras', '7801000000001')->value('id');

        $this->actingAs($this->usuario('encargado@negocio1.cl'))->from("/admin/inventario?sede=$centro")
            ->post('/admin/inventario/ajustar', ['sede_id' => $centro, 'producto_id' => $p, 'tipo' => 'salida', 'cantidad' => 3, 'motivo' => 'Merma'])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame(997, (int) DB::table('stock_sede')->where('sede_id', $centro)->where('producto_id', $p)->value('cantidad'));
        $a = DB::table('ajustes_stock')->first();
        $this->assertSame([1000, 997, -3, 'Merma'], [(int) $a->cantidad_anterior, (int) $a->cantidad_nueva, (int) $a->diferencia, $a->motivo]);

        // Conteo físico: fija el stock.
        $this->post('/admin/inventario/ajustar', ['sede_id' => $centro, 'producto_id' => $p, 'tipo' => 'fijar', 'cantidad' => 4, 'motivo' => 'Conteo'])
            ->assertSessionHas('ok');
        $this->get("/admin/inventario?sede=$centro&bajo=1")->assertOk()->assertSee('Arroz grado 1 1 kg');
    }

    public function test_ajuste_no_deja_stock_negativo_ni_exige_motivo_vacio(): void
    {
        $centro = $this->sede(1, 'CEN');
        $p = DB::table('productos')->where('codigo_barras', '7801000000001')->value('id');
        $this->actingAs($this->usuario('encargado@negocio1.cl'));

        $this->post('/admin/inventario/ajustar', ['sede_id' => $centro, 'producto_id' => $p, 'tipo' => 'salida', 'cantidad' => 1001, 'motivo' => 'Error'])
            ->assertSessionHasErrors('cantidad');
        $this->post('/admin/inventario/ajustar', ['sede_id' => $centro, 'producto_id' => $p, 'tipo' => 'entrada', 'cantidad' => 5, 'motivo' => ''])
            ->assertSessionHasErrors('motivo');
        $this->assertSame(1000, (int) DB::table('stock_sede')->where('sede_id', $centro)->where('producto_id', $p)->value('cantidad'));
        $this->assertSame(0, DB::table('ajustes_stock')->count());
    }

    public function test_no_se_puede_ajustar_stock_de_otro_negocio(): void
    {
        $sedeAjena = $this->sede(2, 'CEN');
        $pAjeno = DB::table('productos')->where('codigo_barras', '7802000000001')->value('id');
        $this->actingAs($this->usuario('dueno@negocio1.cl'))
            ->post('/admin/inventario/ajustar', ['sede_id' => $sedeAjena, 'producto_id' => $pAjeno, 'tipo' => 'fijar', 'cantidad' => 0, 'motivo' => 'Ataque'])
            ->assertNotFound();
        $this->assertSame(1000, (int) DB::table('stock_sede')->where('sede_id', $sedeAjena)->where('producto_id', $pAjeno)->value('cantidad'));
    }
}
