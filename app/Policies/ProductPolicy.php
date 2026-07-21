<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Also governs Item actions on the nested products/{product}/items page —
 * items are always accessed in the context of their parent product, under
 * the same stock.view/stock.manage gate, so callers authorize against the
 * parent Product rather than needing a separate ItemPolicy.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function create(User $user): bool
    {
        return $user->can('stock.manage');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('stock.manage');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can('stock.manage');
    }
}
