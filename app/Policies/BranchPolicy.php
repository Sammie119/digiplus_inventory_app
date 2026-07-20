<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    /**
     * Cross-tenant access is blocked separately by Branch's tenant scope
     * (App\Models\Concerns\BelongsToTenant) — a route-bound branch from
     * another tenant simply fails to resolve before this policy even runs.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('branches.manage');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->can('branches.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('branches.manage');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->can('branches.manage');
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->can('branches.manage');
    }
}
