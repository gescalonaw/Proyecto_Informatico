<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'sku', 'codigo_barras', 'nombre', 'precio_clp', 'costo_clp', 'activo'];

    protected $hidden = ['costo_clp']; // RB-10: la cajera no ve costos

    protected $casts = ['activo' => 'boolean', 'precio_clp' => 'integer'];
}
