<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.view');
    }

    public function view(User $user, Sale $sale): bool
    {
        return $user->can('sales.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales.create');
    }

    public function requestVoid(User $user, Sale $sale): bool
    {
        return $user->can('sales.create')
            && $user->branch_id === $sale->branch_id
            && $sale->status === 'completed'
            && ! $sale->voided_by
            && ! $sale->returns()->exists();
    }

    /**
     * Covers both approving and rejecting a pending void request — the
     * same person is trusted to do either, so one ability gates both
     * actions in the component.
     */
    public function resolveVoid(User $user, Sale $sale): bool
    {
        return $user->can('sales.void.approve')
            && $user->branch_id === $sale->branch_id
            && $sale->voided_by
            && ! $sale->void_approved_by;
    }
}
