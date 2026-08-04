<?php

namespace App\Reports;

use App\Models\SaleItem;
use Illuminate\Support\Collection;

class ItemVelocityData
{
    /**
     * @return Collection<int, object{item_id: int, units_sold: float, revenue: float, last_sold_at: string}>
     */
    public static function build(int $tenantId, ?int $branchId, string $from, string $to): Collection
    {
        return SaleItem::query()
            ->whereHas('sale', function ($query) use ($tenantId, $branchId, $from, $to) {
                $query->where('tenant_id', $tenantId)
                    ->where('status', 'completed')
                    ->whereDate('created_at', '>=', $from)
                    ->whereDate('created_at', '<=', $to);

                if ($branchId) {
                    $query->where('branch_id', $branchId);
                }
            })
            ->selectRaw('item_id, sum(quantity) as units_sold, sum(line_total) as revenue, max(created_at) as last_sold_at')
            ->groupBy('item_id')
            ->get()
            ->keyBy('item_id');
    }
}
