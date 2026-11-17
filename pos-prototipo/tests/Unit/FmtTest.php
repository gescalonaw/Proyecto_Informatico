<?php

namespace Tests\Unit;

use App\Support\Fmt;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fmt: formatos de Chile para el panel (CLP sin decimales, miles con punto, hora de America/Santiago).
 * No necesita Laravel ni base de datos.
 */
class FmtTest extends TestCase
{
    private const MENOS = "\u{2212}"; // el signo "−" que usa Fmt, no el guion "-"

    // ── CLP ────────────────────────────────────────────────────

    #[DataProvider('montosClp')]
    public function test_clp(int|float|null $entrada, string $esperado): void
    {
        $this->assertSame($esperado, Fmt::clp($entrada));
    }

    public static function montosClp(): array
    {
        return [
            'cero' => [0, '$0'],
            'nulo es cero' => [null, '$0'],
            'menos de mil' => [990, '$990'],
            'mil exacto' => [1000, '$1.000'],
            'miles' => [12345, '$12.345'],
            'millones' => [1234567, '$1.234.567'],
            'decimal redondea hacia arriba' => [1234.5, '$1.235'],
            'decimal redondea hacia abajo' => [1234.4, '$1.234'],
            'negativo' => [-1500, self::MENOS . '$1.500'],
            'negativo grande' => [-1234567, self::MENOS . '$1.234.567'],
            'negativo decimal' => [-1500.6, self::MENOS . '$1.501'],
            'casi cero negativo no muestra signo' => [-0.4, '$0'],
        ];
    }

    public function test_clp_negativo_usa_signo_menos_unicode_y_no_guion(): void
    {
        $s = Fmt::clp(-100);

        $this->assertStringStartsWith(self::MENOS, $s);
        $this->assertStringNotContainsString('-', $s);
    }

    public function test_clp_no_usa_comas_ni_decimales(): void
    {
        $s = Fmt::clp(1234567.89);

        $this->assertStringNotContainsString(',', $s);
        $this->assertSame('$1.234.568', $s);
    }

    // ── Números ────────────────────────────────────────────────

    #[DataProvider('numeros')]
    public function test_num(int|float|null $entrada, string $esperado): void
    {
        $this->assertSame($esperado, Fmt::num($entrada));
    }

    public static function numeros(): array
    {
        return [
            'cero' => [0, '0'],
            'nulo' => [null, '0'],
            'pequeño' => [7, '7'],
            'miles' => [1000, '1.000'],
            'millones' => [1234567, '1.234.567'],
            'decimal redondea' => [1234.5, '1.235'],
            'negativo' => [-2500, '-2.500'],
        ];
    }

    // ── Fechas y zona horaria ──────────────────────────────────

    public function test_fecha_nula_o_vacia_muestra_guion_largo(): void
    {
        $this->assertSame('—', Fmt::fecha(null));
        $this->assertSame('—', Fmt::fecha(''));
    }

    public function test_fecha_en_verano_resta_tres_horas(): void
    {
        $this->assertSame('16/11/2026 00:00', Fmt::fecha('2026-11-16 03:00:00'));
        $this->assertSame('15/11/2026 21:30', Fmt::fecha('2026-11-16 00:30:00')); // cruza al día anterior
    }

    public function test_fecha_en_invierno_resta_cuatro_horas(): void
    {
        $this->assertSame('15/07/2026 00:00', Fmt::fecha('2026-07-15 04:00:00'));
        $this->assertSame('14/07/2026 22:15', Fmt::fecha('2026-07-15 02:15:00'));
    }

    public function test_fecha_en_los_dos_lados_del_cambio_de_hora(): void
    {
        // Septiembre: el reloj se adelanta el sábado 5 a medianoche (UTC-4 → UTC-3).
        $this->assertSame('05/09/2026 23:59', Fmt::fecha('2026-09-06 03:59:00')); // antes: UTC-4
        $this->assertSame('06/09/2026 01:00', Fmt::fecha('2026-09-06 04:00:00')); // después: UTC-3

        // Abril: el reloj se atrasa el sábado 4 a medianoche (UTC-3 → UTC-4).
        $this->assertSame('04/04/2026 23:59', Fmt::fecha('2026-04-05 02:59:00')); // antes: UTC-3
        $this->assertSame('04/04/2026 23:00', Fmt::fecha('2026-04-05 03:00:00')); // después: UTC-4
    }

    public function test_fecha_con_formato_personalizado(): void
    {
        $this->assertSame('00:00', Fmt::fecha('2026-11-16 03:00:00', 'H:i'));
        $this->assertSame('16/11', Fmt::fecha('2026-11-16 15:00:00', 'd/m'));
        $this->assertSame('2026-11-16', Fmt::fecha('2026-11-16 15:00:00', 'Y-m-d'));
    }

    public function test_fecha_desde_un_carbon_en_utc(): void
    {
        $utc = Carbon::parse('2026-11-16 03:00:00', 'UTC');

        $this->assertSame('16/11/2026 00:00', Fmt::fecha($utc));
    }

    public function test_fecha_no_modifica_el_objeto_recibido(): void
    {
        $utc = Carbon::parse('2026-11-16 03:00:00', 'UTC');

        Fmt::fecha($utc);

        $this->assertSame('UTC', $utc->getTimezone()->getName());
        $this->assertSame('03:00', $utc->format('H:i'));
    }

    public function test_fecha_desde_un_carbon_inmutable(): void
    {
        $utc = \Carbon\CarbonImmutable::parse('2026-07-15 04:00:00', 'UTC');

        $this->assertSame('15/07/2026 00:00', Fmt::fecha($utc));
    }

    public function test_la_zona_es_santiago(): void
    {
        $this->assertSame('America/Santiago', Fmt::ZONA);
    }

    // ── Medios de pago ─────────────────────────────────────────

    public function test_metodo_traduce_los_cuatro_medios(): void
    {
        $this->assertSame('Efectivo', Fmt::metodo('efectivo'));
        $this->assertSame('Débito', Fmt::metodo('debito'));
        $this->assertSame('Crédito', Fmt::metodo('credito'));
        $this->assertSame('Transferencia', Fmt::metodo('transferencia'));
    }

    public function test_metodo_desconocido_se_devuelve_tal_cual(): void
    {
        $this->assertSame('cheque', Fmt::metodo('cheque'));
        $this->assertSame('', Fmt::metodo(''));
        $this->assertSame('Efectivo ', Fmt::metodo('Efectivo ')); // sin normalizar mayúsculas ni espacios
    }
}
