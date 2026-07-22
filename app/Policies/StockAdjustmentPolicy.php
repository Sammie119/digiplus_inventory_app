<?php

namespace App\Policies;

use App\Models\StockAdjustment;
use App\Models\User;

class StockAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function view(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $user->can('stock.view');
    }

    public function create(User $user): bool
    {
        return $user->can('stock.manage');
    }

    /**
     * Only pending requests can be resolved — once approved or rejected the
     * stock effects (or lack thereof) are final, matching the immutability
     * rule used for received purchases.
     */
    public function approve(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $user->can('stock.adjustments.approve') && $stockAdjustment->status === 'pending';
    }

    public function reject(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $user->can('stock.adjustments.approve') && $stockAdjustment->status === 'pending';
    }
}
