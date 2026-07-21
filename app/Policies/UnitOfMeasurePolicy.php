<?php

namespace App\Policies;

use App\Models\UnitOfMeasure;
use App\Models\User;

class UnitOfMeasurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function create(User $user): bool
    {
        return $user->can('stock.manage');
    }

    public function update(User $user, UnitOfMeasure $unitOfMeasure): bool
    {
        return $user->can('stock.manage');
    }

    public function delete(User $user, UnitOfMeasure $unitOfMeasure): bool
    {
        return $user->can('stock.manage');
    }
}
