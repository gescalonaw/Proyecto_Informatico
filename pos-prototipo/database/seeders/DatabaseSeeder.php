<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Datos sintéticos (sin datos reales de clientes) para el prototipo, la demo de E3 y la PoC-2:
 * 2 negocios × 3 sedes × 1.000 productos, con 1.000 unidades por producto y sede.
 * Códigos de barra del Negocio 1: 7801000000001 ... 7801000001000 (los que usa carga.js).
 *
 * Usuarios (clave "secreto123"), igual para negocio2:
 *   dueno@negocio1.cl      todas las sedes
 *   encargado@negocio1.cl  todas las sedes
 *   cajera@negocio1.cl     Tienda Centro y Tienda Mall
 *   cajera2@negocio1.cl    Tienda Centro (para mostrar la caja compartida)
 * Pantalla POS: http://localhost/.../pos/negocio-1  (y /pos/negocio-2) · Panel: http://localhost/.../admin
 * Además carga 14 días de ventas y cierres sintéticos del Negocio 1 (HistoriaSeeder).
 */
class DatabaseSeeder extends Seeder
{
    /** Nombres de ejemplo de un almacén; se repiten con un número desde el producto 41. */
    private const NOMBRES = [
        'Arroz grado 1 1 kg', 'Azúcar 1 kg', 'Aceite maravilla 1 L', 'Fideos spaghetti 400 g', 'Harina sin polvos 1 kg',
        'Leche entera 1 L', 'Yogur natural 1 L', 'Mantequilla 250 g', 'Queso gauda 250 g', 'Huevos docena',
        'Pan de molde blanco', 'Galletas de agua', 'Café instantáneo 170 g', 'Té 100 bolsitas', 'Mermelada durazno 250 g',
        'Atún en agua 170 g', 'Porotos 1 kg', 'Lentejas 1 kg', 'Salsa de tomate 200 g', 'Sal fina 1 kg',
        'Detergente líquido 3 L', 'Lavalozas 750 ml', 'Papel higiénico 4 rollos', 'Toalla de papel 2 rollos', 'Cloro 1 L',
        'Jabón de tocador', 'Shampoo 400 ml', 'Pasta dental 90 g', 'Bebida cola 1,5 L', 'Agua mineral 1,6 L',
        'Jugo néctar durazno 1,5 L', 'Cerveza lata 470 ml', 'Papas fritas 230 g', 'Chocolate 100 g', 'Caramelos surtidos 150 g',
        'Pilas AA (2)', 'Fósforos', 'Velas (6)', 'Bolsas de basura 10 u', 'Servilletas 100 u',
    ];

    public function run(): void
    {
        $ahora = now();
        $clave = Hash::make('secreto123');
        $n40 = count(self::NOMBRES);

        foreach ([1, 2] as $n) {
            $tenantId = DB::table('tenants')->insertGetId([
                'nombre' => "Negocio $n", 'slug' => "negocio-$n", 'estado' => 'activo',
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            $usuarios = [];
            foreach (['dueno' => ['dueno', 'Dueña'], 'encargado' => ['encargado', 'Encargado'],
                      'cajera' => ['cajera', 'Cajera Ana'], 'cajera2' => ['cajera', 'Cajera Bea']] as $email => [$rol, $nombre]) {
                $usuarios[$email] = DB::table('users')->insertGetId([
                    'tenant_id' => $tenantId, 'name' => "$nombre $n", 'email' => "$email@negocio$n.cl",
                    'rol' => $rol, 'password' => $clave, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }

            $sedes = [];
            foreach ([['Bodega', 'bodega', 'BOD'], ['Tienda Centro', 'tienda', 'CEN'], ['Tienda Mall', 'tienda', 'MALL']] as [$nombre, $tipo, $codigo]) {
                $sedes[$codigo] = DB::table('sedes')->insertGetId([
                    'tenant_id' => $tenantId, 'nombre' => $nombre, 'tipo' => $tipo, 'codigo' => $codigo,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }

            foreach ([['cajera', 'CEN'], ['cajera', 'MALL'], ['cajera2', 'CEN']] as [$u, $s]) {
                DB::table('sede_user')->insert([
                    'tenant_id' => $tenantId, 'sede_id' => $sedes[$s], 'user_id' => $usuarios[$u],
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }

            $productos = [];
            for ($i = 1; $i <= 1000; $i++) {
                $nombre = self::NOMBRES[($i - 1) % $n40] . ($i > $n40 ? ' · ' . $i : '');
                $productos[] = [
                    'tenant_id' => $tenantId,
                    'sku' => sprintf('N%d-SKU-%04d', $n, $i),
                    'codigo_barras' => sprintf('780%d%09d', $n, $i), // negocio 1: 7801000000001…
                    'nombre' => $nombre,
                    'precio_clp' => 990 + 10 * $i,
                    'costo_clp' => 500 + 5 * $i,
                    'activo' => true,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ];
            }
            foreach (array_chunk($productos, 200) as $lote) {
                DB::table('productos')->insert($lote);
            }

            $ids = DB::table('productos')->where('tenant_id', $tenantId)->pluck('id');
            foreach ($sedes as $sedeId) {
                $stock = $ids->map(fn ($pid) => [
                    'tenant_id' => $tenantId, 'producto_id' => $pid, 'sede_id' => $sedeId,
                    'cantidad' => 1000, 'created_at' => $ahora, 'updated_at' => $ahora,
                ])->all();
                foreach (array_chunk($stock, 200) as $lote) {
                    DB::table('stock_sede')->insert($lote);
                }
            }
        }

        // 14 días de ventas y cierres sintéticos del Negocio 1 para el panel (no en las pruebas automáticas).
        if (! app()->runningUnitTests()) {
            $this->call(HistoriaSeeder::class);
        }
    }
}
