<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;

class StockTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function view(User $user, StockTransfer $stockTransfer): bool
    {
        return $user->can('stock.view');
    }

    public function create(User $user): bool
    {
        return $user->can('stock.manage');
    }

    /**
     * Only the source branch (or Business Owner) may edit a still-pending
     * transfer — nothing has shipped yet, so the sending side owns it.
     */
    public function update(User $user, StockTransfer $stockTransfer): bool
    {
        return $user->can('stock.manage')
            && $stockTransfer->status === 'pending'
            && ($user->hasRole('Business Owner') || $user->branch_id === $stockTransfer->from_branch_id);
    }

    public function ship(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->update($user, $stockTransfer);
    }

    public function cancel(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->update($user, $stockTransfer);
    }

    /**
     * Only the destination branch (or Business Owner) may receive — once
     * stock has left the source, only the branch it's headed to can confirm
     * what actually arrived.
     */
    public function receive(User $user, StockTransfer $stockTransfer): bool
    {
        return $user->can('stock.manage')
            && $stockTransfer->status === 'in_transit'
            && ($user->hasRole('Business Owner') || $user->branch_id === $stockTransfer->to_branch_id);
    }
}
