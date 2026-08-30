<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class SaleReceiptController extends Controller
{
    /**
     * Resolves the sale manually (rather than via implicit route-model
     * binding) because binding happens during `SubstituteBindings`, which
     * runs before `SetCurrentTenant` — appended to the end of the `web`
     * middleware group, after the authenticated user is known — has had a
     * chance to set the tenant context that Sale's TenantScope depends on.
     */
    public function __invoke(string $uuid): View
    {
        $sale = Sale::withoutTenantScope()->where('uuid', $uuid)->firstOrFail();

        abort_unless($sale->tenant_id === Auth::user()->tenant_id, 404);

        Gate::authorize('view', $sale);

        $sale->load(['items.item', 'payments', 'customer', 'branch', 'cashier', 'taxLines']);

        return view('receipts.sale', [
            'sale' => $sale,
            'tenantName' => Auth::user()->tenant->name,
        ]);
    }
}
