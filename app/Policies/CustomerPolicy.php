<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('sales.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('sales.create');
    }

    public function recordPayment(User $user, Customer $customer): bool
    {
        return $user->can('sales.create');
    }
}
