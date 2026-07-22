<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $user->can('stock.view');
    }

    public function create(User $user): bool
    {
        return $user->can('stock.manage');
    }

    /**
     * Received purchases are immutable — the stock effects have already
     * fired, so editing afterward would mean silently re-firing them or
     * building reversal logic. Only drafts can be updated.
     */
    public function update(User $user, Purchase $purchase): bool
    {
        return $user->can('stock.manage') && $purchase->status === 'draft';
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return $user->can('stock.manage') && $purchase->status === 'draft';
    }

    public function receive(User $user, Purchase $purchase): bool
    {
        return $user->can('stock.manage') && $purchase->status === 'draft';
    }
}
