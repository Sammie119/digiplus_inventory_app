<?php

namespace App\Models\Scopes;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (CurrentTenant::check()) {
            $builder->where($model->qualifyColumn('tenant_id'), CurrentTenant::id());

            return;
        }

        // No tenant resolved (no authenticated user, console command, Super Admin
        // context). Fail closed rather than silently exposing every tenant's rows —
        // callers that genuinely need cross-tenant access must opt in explicitly via
        // withoutTenantScope() or scopeForTenant().
        $builder->whereRaw('1 = 0');
    }
}
