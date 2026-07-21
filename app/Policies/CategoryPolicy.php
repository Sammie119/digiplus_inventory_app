<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function create(User $user): bool
    {
        return $user->can('stock.manage');
    }

    public function update(User $user, Category $category): bool
    {
        return $user->can('stock.manage');
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->can('stock.manage');
    }
}
