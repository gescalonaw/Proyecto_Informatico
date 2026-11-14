<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\Sede;
use App\Models\Tenant;
use App\Support\PosRespuesta;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class UbicacionController extends Controller
{
    /**
     * GET /api/pos/v1/ubicaciones?negocio={slug}   (pública, para el selector del login)
     * Cambio respecto del plugin: allí se listaban todas las sedes porque había un solo negocio.
     * En el SaaS se exige el negocio, para no revelar las sedes de los demás (RNF-S-6).
     */
    public function index(Request $request)
    {
        TenantContext::set(null);

        $slug = (string) $request->query('negocio', '');
        $tenant = $slug !== '' ? Tenant::where('slug', $slug)->where('estado', 'activo')->first() : null;
        if (! $tenant) {
            return PosRespuesta::error('Negocio no encontrado.', 404);
        }

        $sedes = Sede::where('tenant_id', $tenant->id)
            ->where('tipo', 'tienda') // la bodega no vende
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo']);

        return PosRespuesta::ok(['ubicaciones' => $sedes, 'negocio' => $tenant->nombre]);
    }
}
