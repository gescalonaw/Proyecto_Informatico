<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockSede extends Model
{
    use BelongsToTenant;

    protected $table = 'stock_sede';

    protected $fillable = ['tenant_id', 'producto_id', 'sede_id', 'cantidad'];

    protected $casts = ['cantidad' => 'integer'];
}
