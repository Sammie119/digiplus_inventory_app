<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorSVG;

class ItemBarcodeLabelController extends Controller
{
    /**
     * Resolves the item manually rather than via implicit route-model
     * binding — see App\Http\Controllers\SaleReceiptController for why
     * (SubstituteBindings runs before SetCurrentTenant has set the tenant
     * context that Item's TenantScope depends on).
     */
    public function __invoke(Request $request, string $uuid): View
    {
        Gate::authorize('stock.view');

        $item = Item::withoutTenantScope()->with('product')->where('uuid', $uuid)->firstOrFail();

        abort_unless($item->tenant_id === Auth::user()->tenant_id, 404);

        $value = $item->barcode ?: $item->sku;

        $svg = (new BarcodeGeneratorSVG())->getBarcode($value, BarcodeGenerator::TYPE_CODE_128);

        $copies = max(1, min(50, (int) $request->integer('copies', 6)));

        return view('labels.item', [
            'item' => $item,
            'barcodeValue' => $value,
            'barcodeSvg' => $svg,
            'copies' => $copies,
        ]);
    }
}
