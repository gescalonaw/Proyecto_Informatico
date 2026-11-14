<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Formatos de Chile para las vistas del panel: CLP sin decimales y hora de America/Santiago. */
class Fmt
{
    public const ZONA = 'America/Santiago';

    public static function clp(int|float|null $n): string
    {
        $n = (int) round((float) $n);
        $s = '$' . number_format(abs($n), 0, ',', '.');

        return $n < 0 ? '−' . $s : $s;
    }

    public static function num(int|float|null $n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }

    /** Fecha guardada en UTC → hora local de Chile. */
    public static function fecha(CarbonInterface|string|null $f, string $formato = 'd/m/Y H:i'): string
    {
        if (! $f) {
            return '—';
        }
        $c = $f instanceof CarbonInterface ? Carbon::instance($f) : Carbon::parse($f, 'UTC');

        return $c->setTimezone(self::ZONA)->format($formato);
    }

    public static function metodo(string $m): string
    {
        return ['efectivo' => 'Efectivo', 'debito' => 'Débito', 'credito' => 'Crédito', 'transferencia' => 'Transferencia'][$m] ?? $m;
    }
}
