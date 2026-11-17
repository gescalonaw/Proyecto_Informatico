<?php

namespace Tests\Unit;

use App\Support\Periodo;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Periodo: el rango se elige en hora de Chile y se consulta en UTC.
 * No necesita Laravel ni base de datos; solo congela el reloj con Carbon::setTestNow().
 *
 * Referencia de zona (America/Santiago, 2026): verano UTC-3 (hasta el 4/4 y desde el 5-6/9),
 * invierno UTC-4. El cambio de septiembre ocurre a las 24:00 del sábado 5: el 6 de septiembre
 * el día local parte a las 01:00.
 */
class PeriodoTest extends TestCase
{
    private function ahoraLocal(string $fechaHora): void
    {
        Carbon::setTestNow(Carbon::parse($fechaHora, 'America/Santiago'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Claves ─────────────────────────────────────────────────

    public function test_clave_invalida_o_nula_cae_en_siete_dias(): void
    {
        $this->ahoraLocal('2026-11-16 12:00:00');

        foreach ([null, '', 'ayer', '999', '-1', 'HOY'] as $clave) {
            $this->assertSame('7', Periodo::desde($clave)->clave, 'clave: ' . var_export($clave, true));
        }
    }

    public function test_claves_validas_se_respetan_y_tienen_etiqueta(): void
    {
        $this->ahoraLocal('2026-11-16 12:00:00');

        $this->assertSame('Hoy', Periodo::desde('hoy')->etiqueta());
        $this->assertSame('Últimos 7 días', Periodo::desde('7')->etiqueta());
        $this->assertSame('Últimos 30 días', Periodo::desde('30')->etiqueta());
    }

    // ── Rango local ────────────────────────────────────────────

    public function test_hoy_cubre_el_dia_local_completo(): void
    {
        $this->ahoraLocal('2026-11-16 12:00:00');
        $p = Periodo::desde('hoy');

        $this->assertSame('2026-11-16 00:00:00', $p->desdeLocal->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-16 23:59:59', $p->hastaLocal->format('Y-m-d H:i:s'));
        $this->assertSame(['2026-11-16'], $p->dias());
    }

    public function test_siete_y_treinta_dias_incluyen_hoy(): void
    {
        $this->ahoraLocal('2026-11-16 12:00:00');

        $siete = Periodo::desde('7');
        $this->assertSame('2026-11-10', $siete->desdeLocal->format('Y-m-d'));
        $this->assertCount(7, $siete->dias());
        $this->assertSame('2026-11-10', $siete->dias()[0]);
        $this->assertSame('2026-11-16', $siete->dias()[6]);

        $treinta = Periodo::desde('30');
        $this->assertSame('2026-10-18', $treinta->desdeLocal->format('Y-m-d'));
        $this->assertCount(30, $treinta->dias());
    }

    public function test_los_dias_son_consecutivos_y_sin_repetidos(): void
    {
        $this->ahoraLocal('2026-11-16 12:00:00');
        $dias = Periodo::desde('30')->dias();

        $this->assertSame($dias, array_values(array_unique($dias)));
        for ($i = 1; $i < count($dias); $i++) {
            $this->assertSame(
                Carbon::parse($dias[$i - 1])->addDay()->format('Y-m-d'),
                $dias[$i],
                "El día $i no sigue al anterior"
            );
        }
    }

    // ── Conversión a UTC ───────────────────────────────────────

    public function test_conversion_a_utc_en_verano_es_mas_tres_horas(): void
    {
        $this->ahoraLocal('2026-11-16 12:00:00'); // UTC-3
        $p = Periodo::desde('hoy');

        $this->assertSame('2026-11-16 03:00:00', $p->desdeUtc());
        $this->assertSame('2026-11-17 02:59:59', $p->hastaUtc());
    }

    public function test_conversion_a_utc_en_invierno_es_mas_cuatro_horas(): void
    {
        $this->ahoraLocal('2026-07-15 12:00:00'); // UTC-4
        $p = Periodo::desde('hoy');

        $this->assertSame('2026-07-15 04:00:00', $p->desdeUtc());
        $this->assertSame('2026-07-16 03:59:59', $p->hastaUtc());
    }

    public function test_de_noche_en_chile_ya_es_otro_dia_en_utc_pero_hoy_sigue_siendo_hoy(): void
    {
        // 22:24 del 16/11 en Chile = 01:24 UTC del 17/11. "Hoy" debe ser el 16 local.
        $this->ahoraLocal('2026-11-16 22:24:00');
        $p = Periodo::desde('hoy');

        $this->assertSame(['2026-11-16'], $p->dias());
        $this->assertSame('2026-11-16 03:00:00', $p->desdeUtc());
        $this->assertSame('2026-11-17 02:59:59', $p->hastaUtc());
    }

    public function test_justo_despues_de_medianoche_local_hoy_ya_es_el_dia_nuevo(): void
    {
        $this->ahoraLocal('2026-11-17 00:01:00');

        $this->assertSame(['2026-11-17'], Periodo::desde('hoy')->dias());
    }

    public function test_formato_utc_es_el_de_la_base_de_datos(): void
    {
        $this->ahoraLocal('2026-11-16 12:00:00');
        $p = Periodo::desde('7');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $p->desdeUtc());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $p->hastaUtc());
        $this->assertLessThan($p->hastaUtc(), $p->desdeUtc());
    }

    // ── Cambio de hora ─────────────────────────────────────────

    public function test_rango_que_cruza_el_cambio_a_horario_de_verano(): void
    {
        // Semana del 2 al 8 de septiembre de 2026: el 5 a medianoche se adelanta el reloj.
        $this->ahoraLocal('2026-09-08 12:00:00');
        $p = Periodo::desde('7');

        // El inicio (2/9) está en invierno (UTC-4) y el fin (8/9) en verano (UTC-3).
        $this->assertSame('2026-09-02 04:00:00', $p->desdeUtc());
        $this->assertSame('2026-09-09 02:59:59', $p->hastaUtc());

        // El día del cambio no se pierde ni se duplica.
        $this->assertSame(
            ['2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05', '2026-09-06', '2026-09-07', '2026-09-08'],
            $p->dias()
        );
    }

    public function test_rango_que_cruza_el_cambio_a_horario_de_invierno(): void
    {
        // Semana del 1 al 7 de abril de 2026: el 4 a medianoche se atrasa el reloj.
        $this->ahoraLocal('2026-04-07 12:00:00');
        $p = Periodo::desde('7');

        // El inicio (1/4) está en verano (UTC-3) y el fin (7/4) en invierno (UTC-4).
        $this->assertSame('2026-04-01 03:00:00', $p->desdeUtc());
        $this->assertSame('2026-04-08 03:59:59', $p->hastaUtc());
        $this->assertCount(7, $p->dias());
        $this->assertSame(
            ['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-04', '2026-04-05', '2026-04-06', '2026-04-07'],
            $p->dias()
        );
    }

    public function test_el_dia_de_cambio_de_hora_no_dura_24_horas_en_utc(): void
    {
        // El 6/9 empieza a la 01:00 local (UTC-3 = 04:00 UTC) y el 5/9 a las 00:00 local (UTC-4 = 04:00 UTC):
        // ese sábado dura 24 h, pero el domingo del cambio dura 23 h.
        $this->ahoraLocal('2026-09-06 12:00:00');
        $hoy = Periodo::desde('hoy');

        $inicio = Carbon::createFromFormat('Y-m-d H:i:s', $hoy->desdeUtc(), 'UTC');
        $fin = Carbon::createFromFormat('Y-m-d H:i:s', $hoy->hastaUtc(), 'UTC');

        // 23 h menos un segundo (el rango termina en 23:59:59).
        $this->assertSame(23 * 3600 - 1, (int) $inicio->diffInSeconds($fin));
    }
}
