<?php

namespace App\Policies;

use App\Models\StockTake;
use App\Models\User;

class StockTakePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function view(User $user, StockTake $stockTake): bool
    {
        return $user->can('stock.view');
    }

    public function create(User $user): bool
    {
        return $user->can('stock.manage');
    }

    /**
     * Covers recording counts, completing, and cancelling a stock take.
     * The specific status-transition legality (e.g. can't record counts on
     * an already-approved stock take) is checked inline in the Volt
     * component, same convention used elsewhere for record-state rules.
     */
    public function update(User $user, StockTake $stockTake): bool
    {
        return $user->can('stock.manage');
    }

    /**
     * Only a stock take awaiting approval can be resolved.
     */
    public function approve(User $user, StockTake $stockTake): bool
    {
        return $user->can('stock.adjustments.approve') && $stockTake->status === 'pending_approval';
    }

    public function reject(User $user, StockTake $stockTake): bool
    {
        return $user->can('stock.adjustments.approve') && $stockTake->status === 'pending_approval';
    }
}
