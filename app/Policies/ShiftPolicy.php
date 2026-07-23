<?php

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;

class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.view');
    }

    public function view(User $user, Shift $shift): bool
    {
        return $user->can('sales.view');
    }

    public function open(User $user): bool
    {
        if (! $user->can('sales.create')) {
            return false;
        }

        return ! Shift::where('cashier_id', $user->id)
            ->where('branch_id', $user->branch_id)
            ->where('status', 'open')
            ->exists();
    }

    /**
     * Only the shift's own cashier (or Business Owner) can close it —
     * closing is a personal cash-drawer reconciliation, not a branch-wide
     * management action.
     */
    public function close(User $user, Shift $shift): bool
    {
        return $user->can('sales.create')
            && $shift->status === 'open'
            && ($user->hasRole('Business Owner') || $user->id === $shift->cashier_id);
    }
}
