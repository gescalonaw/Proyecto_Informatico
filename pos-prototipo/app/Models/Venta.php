<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'sede_id', 'usuario_id', 'registrada_por', 'turno_caja_id',
        'referencia', 'subtotal_clp', 'descuento_clp', 'total_clp'];
}
