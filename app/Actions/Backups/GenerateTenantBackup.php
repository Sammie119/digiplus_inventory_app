<?php

namespace App\Actions\Backups;

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Shift;
use App\Models\StockAdjustment;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\TenantBackup;
use App\Models\UnitOfMeasure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class GenerateTenantBackup
{
    private const RETENTION = 10;

    public function __invoke(int $tenantId, ?int $generatedBy = null): TenantBackup
    {
        $disk = Storage::disk('local');
        $relativePath = "backups/{$tenantId}/".now()->format('Ymd-His').'.zip';
        $disk->makeDirectory("backups/{$tenantId}");

        $zip = new ZipArchive;
        $zip->open($disk->path($relativePath), ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($this->tables($tenantId) as $filename => $rows) {
            if ($rows->isNotEmpty()) {
                $zip->addFromString($filename, $this->toCsv($rows));
            }
        }

        $zip->close();

        $backup = TenantBackup::create([
            'tenant_id' => $tenantId,
            'file_path' => $relativePath,
            'size_bytes' => $disk->size($relativePath),
            'generated_by' => $generatedBy,
        ]);

        $this->pruneOld($tenantId, $disk);

        return $backup;
    }

    /**
     * @return array<string, Collection>
     */
    private function tables(int $tenantId): array
    {
        return [
            'categories.csv' => Category::where('tenant_id', $tenantId)->get(),
            'units_of_measure.csv' => UnitOfMeasure::where('tenant_id', $tenantId)->get(),
            'tax_rates.csv' => TaxRate::where('tenant_id', $tenantId)->get(),
            'branches.csv' => Branch::where('tenant_id', $tenantId)->get(),
            'suppliers.csv' => Supplier::where('tenant_id', $tenantId)->get(),
            'items.csv' => Item::where('tenant_id', $tenantId)->get(),
            'products.csv' => Product::where('tenant_id', $tenantId)->get(),
            'batches.csv' => Batch::where('tenant_id', $tenantId)->get(),
            'stock_levels.csv' => StockLevel::where('tenant_id', $tenantId)->get(),
            'stock_movements.csv' => StockMovement::where('tenant_id', $tenantId)->get(),
            'stock_adjustments.csv' => StockAdjustment::where('tenant_id', $tenantId)->get(),
            'stock_transfers.csv' => StockTransfer::where('tenant_id', $tenantId)->get(),
            'stock_transfer_items.csv' => StockTransferItem::whereHas('stockTransfer', fn ($q) => $q->where('tenant_id', $tenantId))->get(),
            'purchases.csv' => Purchase::where('tenant_id', $tenantId)->get(),
            'purchase_items.csv' => PurchaseItem::whereHas('purchase', fn ($q) => $q->where('tenant_id', $tenantId))->get(),
            'customers.csv' => Customer::where('tenant_id', $tenantId)->get(),
            'customer_ledger_entries.csv' => CustomerLedgerEntry::where('tenant_id', $tenantId)->get(),
            'sales.csv' => Sale::where('tenant_id', $tenantId)->get(),
            'sale_items.csv' => SaleItem::whereHas('sale', fn ($q) => $q->where('tenant_id', $tenantId))->get(),
            'payments.csv' => Payment::whereHas('sale', fn ($q) => $q->where('tenant_id', $tenantId))->get(),
            'sale_returns.csv' => SaleReturn::where('tenant_id', $tenantId)->get(),
            'sale_return_items.csv' => SaleReturnItem::whereHas('saleReturn', fn ($q) => $q->where('tenant_id', $tenantId))->get(),
            'shifts.csv' => Shift::where('tenant_id', $tenantId)->get(),
        ];
    }

    private function toCsv(Collection $rows): string
    {
        $handle = fopen('php://temp', 'w+');

        fputcsv($handle, array_keys($rows->first()->toArray()));

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn ($value) => is_array($value) ? json_encode($value) : $value,
                array_values($row->toArray()),
            ));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function pruneOld(int $tenantId, $disk): void
    {
        $stale = TenantBackup::where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->skip(self::RETENTION)
            ->take(PHP_INT_MAX)
            ->get();

        foreach ($stale as $old) {
            $disk->delete($old->file_path);
            $old->delete();
        }
    }
}
