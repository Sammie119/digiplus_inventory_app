<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;

class SaleReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.view');
    }

    public function view(User $user, SaleReturn $saleReturn): bool
    {
        return $user->can('sales.view');
    }

    public function create(User $user, Sale $sale): bool
    {
        return $user->can('sales.create')
            && $user->branch_id === $sale->branch_id
            && $sale->status === 'completed'
            && ! $sale->voided_by;
    }

    /**
     * Covers both approving and cancelling a pending return request.
     */
    public function resolve(User $user, SaleReturn $saleReturn): bool
    {
        return $user->can('sales.void.approve')
            && $user->branch_id === $saleReturn->branch_id
            && $saleReturn->status === 'pending';
    }
}
