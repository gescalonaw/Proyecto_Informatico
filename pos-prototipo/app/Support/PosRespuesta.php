<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Formato de respuesta del plugin ({success, data, message}), para que la pantalla POS
 * reciclada funcione sin cambios. En los errores se repite el texto en "error" por
 * compatibilidad con los scripts de la PoC-2.
 */
class PosRespuesta
{
    public static function ok(array $data = [], string $mensaje = '', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => $mensaje], $status);
    }

    public static function error(string $mensaje, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $mensaje, 'error' => $mensaje], $status);
    }
}
