<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Caja compartida de una sede: se abre una vez y todas las cajeras venden sobre ella (RF-6). */
class TurnoCaja extends Model
{
    use BelongsToTenant;

    protected $table = 'turnos_caja';

    protected $guarded = ['id'];

    protected $casts = [
        'abierta_en' => 'datetime',
        'cerrada_en' => 'datetime',
    ];

    public static function abiertaEn(Sede $sede): ?self
    {
        return static::where('sede_id', $sede->id)->where('estado', 'abierta')->latest('id')->first();
    }
}
