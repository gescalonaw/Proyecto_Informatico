<?php

namespace Tests\Unit;

use App\Models\Producto;
use App\Models\Sede;
use App\Models\StockSede;
use App\Models\Tenant;
use App\Models\User;
use App\Services\VentaRechazada;
use App\Services\VentaService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * VentaService aislado: se llama directo, sin HTTP, tokens ni middleware.
 * Usa base SQLite en memoria con solo los datos mínimos de cada prueba (no el seeder).
 *
 * Reglas bajo prueba: RB-1 (stock nunca negativo, venta atómica) y RB-2 (pagos = total),
 * más el redondeo del descuento (CLP entero, una sola vez) y el agrupado de líneas repetidas.
 */
class VentaServiceTest extends TestCase
{
    use RefreshDatabase;

    private VentaService $servicio;
    private Tenant $tenant;
    private Sede $sede;
    private User $cajera;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::set(null); // estado estático: se parte siempre sin negocio activo

        $this->servicio = new VentaService();
        $this->tenant = Tenant::create(['nombre' => 'Negocio Unit', 'slug' => 'negocio-unit', 'estado' => 'activo']);
        $this->sede = Sede::create(['tenant_id' => $this->tenant->id, 'nombre' => 'Tienda', 'tipo' => 'tienda', 'codigo' => 'TDA']);
        $this->cajera = $this->usuario('cajera@unit.cl', 'cajera');
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    // ── Ayudas ─────────────────────────────────────────────────

    private function usuario(string $email, string $rol): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($rol), 'email' => $email,
            'rol' => $rol, 'password' => 'secreto123',
        ]);
    }

    private function producto(int $precio, ?int $stock, bool $activo = true): Producto
    {
        $this->n++;
        $p = Producto::create([
            'tenant_id' => $this->tenant->id, 'sku' => "SKU-$this->n", 'codigo_barras' => "780000000000$this->n",
            'nombre' => "Producto $this->n", 'precio_clp' => $precio, 'costo_clp' => (int) ($precio / 2), 'activo' => $activo,
        ]);
        if ($stock !== null) {
            StockSede::create(['tenant_id' => $this->tenant->id, 'producto_id' => $p->id, 'sede_id' => $this->sede->id, 'cantidad' => $stock]);
        }

        return $p;
    }

    private function stock(Producto $p): int
    {
        return (int) DB::table('stock_sede')->where('producto_id', $p->id)->where('sede_id', $this->sede->id)->value('cantidad');
    }

    private function vender(array $items, array $pagos, float $pct = 0, ?int $totalDeclarado = null, ?User $registradaPor = null)
    {
        return $this->servicio->registrar($this->sede, $this->cajera, $items, $pagos, $pct, null, $registradaPor, $totalDeclarado);
    }

    private function efectivo(int $monto): array
    {
        return [['metodo' => 'efectivo', 'monto' => $monto]];
    }

    // ── Redondeo del descuento ─────────────────────────────────

    #[DataProvider('descuentos')]
    public function test_redondeo_del_descuento(int $precio, int $cantidad, float $pct, int $descuentoEsperado, int $totalEsperado): void
    {
        $p = $this->producto($precio, 100);

        $venta = $this->vender([['producto_id' => $p->id, 'cantidad' => $cantidad]], $this->efectivo($totalEsperado), $pct);

        $this->assertSame($precio * $cantidad, (int) $venta->subtotal_clp);
        $this->assertSame($descuentoEsperado, (int) $venta->descuento_clp);
        $this->assertSame($totalEsperado, (int) $venta->total_clp);
    }

    public static function descuentos(): array
    {
        return [
            'sin descuento' => [1000, 3, 0.0, 0, 3000],
            '10 % exacto' => [1000, 3, 10.0, 300, 2700],
            '15 % de 999 = 149,85 sube a 150' => [999, 1, 15.0, 150, 849],
            '10 % de 1005 = 100,5 redondea hacia arriba (101)' => [1005, 1, 10.0, 101, 904],
            '5 % de 1010 = 50,5 redondea hacia arriba (51)' => [1010, 1, 5.0, 51, 959],
            '33,3 % de 999 = 332,667 sube a 333' => [999, 1, 33.3, 333, 666],
            '1 % de 149 = 1,49 baja a 1' => [149, 1, 1.0, 1, 148],
            'porcentaje decimal 2,5 %' => [2000, 1, 2.5, 50, 1950],
            '100 % deja el total en cero' => [1500, 2, 100.0, 3000, 0],
        ];
    }

    public function test_descuento_sobre_100_se_limita_a_100(): void
    {
        $p = $this->producto(1000, 10);

        $venta = $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], [], 250.0);

        $this->assertSame(1000, (int) $venta->descuento_clp);
        $this->assertSame(0, (int) $venta->total_clp);
    }

    public function test_descuento_negativo_se_trata_como_cero(): void
    {
        $p = $this->producto(1000, 10);

        $venta = $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000), -20.0);

        $this->assertSame(0, (int) $venta->descuento_clp);
        $this->assertSame(1000, (int) $venta->total_clp);
    }

    public function test_el_descuento_se_redondea_una_sola_vez_sobre_el_subtotal(): void
    {
        // Dos líneas de 333 con 33,3 %: por línea darían 111 + 111 = 222;
        // sobre el subtotal (666) da round(221,778) = 222. Con precio 335 × 2 + 336 el caso se separa:
        // subtotal 1006, 10 % = 100,6 → 101 (por línea sería 33,5→34 + 33,5→34 + 33,6→34 = 102).
        $a = $this->producto(335, 10);
        $b = $this->producto(336, 10);

        $venta = $this->vender(
            [['producto_id' => $a->id, 'cantidad' => 2], ['producto_id' => $b->id, 'cantidad' => 1]],
            $this->efectivo(905),
            10.0
        );

        $this->assertSame(1006, (int) $venta->subtotal_clp);
        $this->assertSame(101, (int) $venta->descuento_clp);
        $this->assertSame(905, (int) $venta->total_clp);
    }

    // ── Líneas repetidas ───────────────────────────────────────

    public function test_el_mismo_producto_repetido_se_suma_en_una_sola_linea(): void
    {
        $p = $this->producto(1000, 10);

        $venta = $this->vender(
            [['producto_id' => $p->id, 'cantidad' => 2], ['producto_id' => $p->id, 'cantidad' => 3]],
            $this->efectivo(5000)
        );

        $lineas = DB::table('venta_lineas')->where('venta_id', $venta->id)->get();
        $this->assertCount(1, $lineas);
        $this->assertSame(5, (int) $lineas[0]->cantidad);
        $this->assertSame(1000, (int) $lineas[0]->precio_unitario_clp);
        $this->assertSame(5000, (int) $venta->subtotal_clp);
        $this->assertSame(5, $this->stock($p));
    }

    public function test_repetidos_validan_el_stock_con_la_suma_no_por_separado(): void
    {
        $p = $this->producto(1000, 4);

        // Cada línea (2 y 3) cabe por separado en 4, pero juntas (5) no.
        try {
            $this->vender(
                [['producto_id' => $p->id, 'cantidad' => 2], ['producto_id' => $p->id, 'cantidad' => 3]],
                $this->efectivo(5000)
            );
            $this->fail('Debió rechazar la venta por stock insuficiente.');
        } catch (VentaRechazada $e) {
            $this->assertStringContainsString('Stock insuficiente', $e->getMessage());
        }

        $this->assertSame(4, $this->stock($p));
        $this->assertSame(0, DB::table('ventas')->count());
    }

    public function test_productos_distintos_generan_una_linea_cada_uno_con_el_precio_de_la_base(): void
    {
        $a = $this->producto(1200, 10);
        $b = $this->producto(800, 10);

        $venta = $this->vender(
            [['producto_id' => $b->id, 'cantidad' => 1], ['producto_id' => $a->id, 'cantidad' => 2, 'precio_unitario' => 1]],
            $this->efectivo(3200)
        );

        $this->assertSame(3200, (int) $venta->total_clp); // el precio_unitario del cliente se ignora
        $this->assertSame(2, DB::table('venta_lineas')->where('venta_id', $venta->id)->count());
        $this->assertSame(8, $this->stock($a));
        $this->assertSame(9, $this->stock($b));
    }

    // ── VentaRechazada ─────────────────────────────────────────

    public function test_stock_insuficiente_rechaza_y_no_toca_nada(): void
    {
        $p = $this->producto(1000, 2);

        try {
            $this->vender([['producto_id' => $p->id, 'cantidad' => 3]], $this->efectivo(3000));
            $this->fail('Debió lanzar VentaRechazada.');
        } catch (VentaRechazada $e) {
            $this->assertStringContainsString('Stock insuficiente', $e->getMessage());
            $this->assertStringContainsString('quedan 2', $e->getMessage());
        }

        $this->assertSame(2, $this->stock($p));
        $this->assertSame(0, DB::table('ventas')->count());
        $this->assertSame(0, DB::table('venta_lineas')->count());
        $this->assertSame(0, DB::table('venta_pagos')->count());
    }

    public function test_vender_exactamente_el_stock_disponible_deja_cero(): void
    {
        $p = $this->producto(1000, 5);

        $this->vender([['producto_id' => $p->id, 'cantidad' => 5]], $this->efectivo(5000));

        $this->assertSame(0, $this->stock($p));
    }

    public function test_sin_fila_de_stock_en_la_sede_cuenta_como_cero(): void
    {
        $p = $this->producto(1000, null); // existe el producto, pero nunca se cargó stock en esta sede

        $this->expectException(VentaRechazada::class);
        $this->expectExceptionMessage('quedan 0');

        $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000));
    }

    public function test_stock_de_otra_sede_no_sirve(): void
    {
        $p = $this->producto(1000, null);
        $otra = Sede::create(['tenant_id' => $this->tenant->id, 'nombre' => 'Bodega', 'tipo' => 'bodega', 'codigo' => 'BOD']);
        StockSede::create(['tenant_id' => $this->tenant->id, 'producto_id' => $p->id, 'sede_id' => $otra->id, 'cantidad' => 50]);

        $this->expectException(VentaRechazada::class);

        $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000));
    }

    public function test_producto_inexistente_se_rechaza(): void
    {
        $this->expectException(VentaRechazada::class);
        $this->expectExceptionMessage('no existe');

        $this->vender([['producto_id' => 999999, 'cantidad' => 1]], $this->efectivo(1000));
    }

    public function test_producto_inactivo_se_rechaza(): void
    {
        $p = $this->producto(1000, 10, activo: false);

        try {
            $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000));
            $this->fail('Debió lanzar VentaRechazada.');
        } catch (VentaRechazada $e) {
            $this->assertStringContainsString('no existe', $e->getMessage());
        }

        $this->assertSame(10, $this->stock($p));
    }

    public function test_si_una_linea_falla_se_revierte_el_stock_de_las_anteriores(): void
    {
        // El servicio recorre los productos ordenados por id: el primero se descuenta y el segundo falla.
        $a = $this->producto(1000, 10);
        $b = $this->producto(1000, 1);

        try {
            $this->vender(
                [['producto_id' => $a->id, 'cantidad' => 4], ['producto_id' => $b->id, 'cantidad' => 2]],
                $this->efectivo(6000)
            );
            $this->fail('Debió lanzar VentaRechazada.');
        } catch (VentaRechazada $e) {
            $this->assertStringContainsString("Producto {$b->id}", $e->getMessage());
        }

        $this->assertSame(10, $this->stock($a), 'El descuento de la primera línea debió revertirse (RB-1: venta atómica).');
        $this->assertSame(1, $this->stock($b));
        $this->assertSame(0, DB::table('ventas')->count());
    }

    public function test_pagos_que_no_suman_el_total_se_rechazan_y_revierten_el_stock(): void
    {
        $p = $this->producto(1000, 10);

        try {
            $this->vender([['producto_id' => $p->id, 'cantidad' => 2]], $this->efectivo(1999));
            $this->fail('Debió lanzar VentaRechazada.');
        } catch (VentaRechazada $e) {
            $this->assertStringContainsString('no suman', $e->getMessage());
            $this->assertStringContainsString('1999', $e->getMessage());
            $this->assertStringContainsString('2000', $e->getMessage());
        }

        $this->assertSame(10, $this->stock($p));
        $this->assertSame(0, DB::table('ventas')->count());
    }

    public function test_pagos_de_mas_tambien_se_rechazan(): void
    {
        $p = $this->producto(1000, 10);

        $this->expectException(VentaRechazada::class);

        $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1001));
    }

    public function test_sin_pagos_solo_es_valido_si_el_total_es_cero(): void
    {
        $p = $this->producto(1000, 10);

        $this->expectException(VentaRechazada::class);

        $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], []);
    }

    public function test_total_declarado_distinto_del_calculado_se_rechaza(): void
    {
        $p = $this->producto(1000, 10);

        try {
            $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000), 0, 900);
            $this->fail('Debió lanzar VentaRechazada.');
        } catch (VentaRechazada $e) {
            $this->assertStringContainsString('El total cambió', $e->getMessage());
            $this->assertStringContainsString('900', $e->getMessage());
            $this->assertStringContainsString('1000', $e->getMessage());
        }

        $this->assertSame(10, $this->stock($p));
    }

    public function test_total_declarado_igual_al_calculado_se_acepta(): void
    {
        $p = $this->producto(999, 10);

        $venta = $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(849), 15.0, 849);

        $this->assertSame(849, (int) $venta->total_clp);
    }

    public function test_venta_rechazada_es_una_runtime_exception(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new VentaRechazada('x'));
    }

    // ── Lo que queda guardado ──────────────────────────────────

    public function test_venta_exitosa_guarda_cabecera_lineas_pagos_y_descuenta_stock(): void
    {
        $p = $this->producto(1000, 10);

        $venta = $this->vender(
            [['producto_id' => $p->id, 'cantidad' => 3]],
            [['metodo' => 'efectivo', 'monto' => 1000], ['metodo' => 'debito', 'monto' => 2000]]
        );

        $this->assertSame($this->tenant->id, (int) $venta->tenant_id);
        $this->assertSame($this->sede->id, (int) $venta->sede_id);
        $this->assertSame($this->cajera->id, (int) $venta->usuario_id);
        $this->assertNull($venta->turno_caja_id);
        $this->assertSame(3000, (int) $venta->total_clp);
        $this->assertSame(7, $this->stock($p));

        $pagos = DB::table('venta_pagos')->where('venta_id', $venta->id)->orderBy('id')->get();
        $this->assertSame(['efectivo', 'debito'], $pagos->pluck('metodo')->all());
        $this->assertSame([1000, 2000], $pagos->pluck('monto_clp')->map(fn ($m) => (int) $m)->all());
    }

    public function test_registrada_por_es_la_cajera_si_no_se_indica_otra_titular(): void
    {
        $p = $this->producto(1000, 10);

        $venta = $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000));

        $this->assertSame($this->cajera->id, (int) $venta->registrada_por);
    }

    public function test_registrada_por_guarda_al_titular_de_la_sesion_cuando_difiere_de_la_cajera_activa(): void
    {
        $p = $this->producto(1000, 10);
        $encargado = $this->usuario('encargado@unit.cl', 'encargado');

        $venta = $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000), 0, null, $encargado);

        $this->assertSame($this->cajera->id, (int) $venta->usuario_id);
        $this->assertSame($encargado->id, (int) $venta->registrada_por);
    }

    public function test_la_referencia_tiene_formato_y_es_unica(): void
    {
        $p = $this->producto(1000, 10);

        $v1 = $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000));
        $v2 = $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000));

        $this->assertMatchesRegularExpression('/^VTA-\d{8}-[A-Z0-9]{8}$/', $v1->referencia);
        $this->assertNotSame($v1->referencia, $v2->referencia);
        $this->assertSame(8, $this->stock($p));
    }

    public function test_ventas_sucesivas_agotan_el_stock_y_la_siguiente_se_rechaza(): void
    {
        $p = $this->producto(1000, 2);

        $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000));
        $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000));

        $this->assertSame(0, $this->stock($p));

        $this->expectException(VentaRechazada::class);
        $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000));
    }

    public function test_el_stock_nunca_queda_negativo_tras_una_secuencia_de_intentos(): void
    {
        $p = $this->producto(1000, 5);
        $aceptadas = 0;

        for ($i = 0; $i < 12; $i++) {
            try {
                $this->vender([['producto_id' => $p->id, 'cantidad' => 1]], $this->efectivo(1000));
                $aceptadas++;
            } catch (VentaRechazada) {
                // esperado a partir de la sexta
            }
        }

        $this->assertSame(5, $aceptadas);
        $this->assertSame(0, $this->stock($p));
        $this->assertSame(5, DB::table('ventas')->count());
    }
}
