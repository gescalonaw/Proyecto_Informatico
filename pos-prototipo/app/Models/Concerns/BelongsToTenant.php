<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aislamiento por negocio (RB-5, RNF-S-6): cuando hay un negocio activo en la petición,
 * toda consulta del modelo se filtra por tenant_id y todo registro nuevo lo recibe.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if (TenantContext::id() !== null) {
                $query->where($query->getModel()->getTable() . '.tenant_id', TenantContext::id());
            }
        });

        static::creating(function ($model) {
            if (TenantContext::id() !== null && empty($model->tenant_id)) {
                $model->tenant_id = TenantContext::id();
            }
        });
    }
}
