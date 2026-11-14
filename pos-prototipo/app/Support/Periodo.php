<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Rango de fechas del panel. Se elige en hora de Chile y se consulta en UTC,
 * que es como se guardan las fechas.
 */
class Periodo
{
    public const OPCIONES = ['hoy' => 'Hoy', '7' => 'Últimos 7 días', '30' => 'Últimos 30 días'];

    public function __construct(
        public readonly string $clave,
        public readonly Carbon $desdeLocal,
        public readonly Carbon $hastaLocal,
    ) {}

    public static function desde(?string $clave): self
    {
        $clave = array_key_exists((string) $clave, self::OPCIONES) ? (string) $clave : '7';
        $hoy = Carbon::now(Fmt::ZONA);
        $desde = $clave === 'hoy' ? $hoy->copy()->startOfDay() : $hoy->copy()->subDays((int) $clave - 1)->startOfDay();

        return new self($clave, $desde, $hoy->copy()->endOfDay());
    }

    public function desdeUtc(): string
    {
        return $this->desdeLocal->copy()->utc()->format('Y-m-d H:i:s');
    }

    public function hastaUtc(): string
    {
        return $this->hastaLocal->copy()->utc()->format('Y-m-d H:i:s');
    }

    /** Días del rango (Y-m-d locales), para que los días sin ventas también aparezcan. */
    public function dias(): array
    {
        $out = [];
        for ($d = $this->desdeLocal->copy(); $d->lte($this->hastaLocal); $d->addDay()) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    public function etiqueta(): string
    {
        return self::OPCIONES[$this->clave];
    }
}
