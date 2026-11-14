<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Sede extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'nombre', 'tipo', 'codigo'];

    /** Dueño/a y encargado/a trabajan en todas las sedes de su negocio; la cajera, solo en las asignadas. */
    public function permiteA(User $user): bool
    {
        if ((int) $user->tenant_id !== (int) $this->tenant_id) {
            return false;
        }
        if (in_array($user->rol, ['dueno', 'encargado'], true)) {
            return true;
        }

        return DB::table('sede_user')->where('sede_id', $this->id)->where('user_id', $user->id)->exists();
    }
}
