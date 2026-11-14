<?php

namespace App\Support;

/** Negocio (tenant) de la petición en curso. Lo fija el middleware PosTokenAuth. */
class TenantContext
{
    private static ?int $id = null;

    public static function set(?int $id): void
    {
        self::$id = $id;
    }

    public static function id(): ?int
    {
        return self::$id;
    }
}
