<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (empty($model->tenant_id) && CurrentTenant::check()) {
                $model->tenant_id = CurrentTenant::id();
            }
        });
    }

    /**
     * Query across all tenants regardless of the resolved tenant context.
     * Use for Super Admin screens and console tooling only.
     */
    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId);
    }
}
