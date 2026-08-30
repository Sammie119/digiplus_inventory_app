<?php

namespace App\Reports;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

class ProfitLossReportData
{
    public static function build(int $tenantId, ?int $branchId, string $from, string $to): array
    {
        $sales = static::completedSales($tenantId, $branchId, $from, $to);
        $returns = static::approvedReturns($tenantId, $branchId, $from, $to);
        $shrinkageMovements = static::shrinkageMovements($tenantId, $branchId, $from, $to);

        return [
            'summary' => static::summary($sales, $returns, $shrinkageMovements),
            'dailyBreakdown' => static::dailyBreakdown($sales, $returns, $shrinkageMovements),
            'categoryBreakdown' => static::categoryBreakdown($sales, $returns),
        ];
    }

    private static function completedSales(int $tenantId, ?int $branchId, string $from, string $to)
    {
        $query = Sale::with('items.item.product.category')
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->get();
    }

    private static function approvedReturns(int $tenantId, ?int $branchId, string $from, string $to)
    {
        $query = SaleReturn::with('items.saleItem')
            ->where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->get();
    }

    private static function shrinkageMovements(int $tenantId, ?int $branchId, string $from, string $to)
    {
        $query = StockMovement::where('tenant_id', $tenantId)
            ->where('movement_type', 'adjustment')
            ->where('quantity', '<', 0)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->get();
    }

    /**
     * @return array{0: float, 1: float} [returnedRevenue, returnedCost]
     */
    private static function returnedRevenueAndCost(Collection $returns): array
    {
        $revenue = 0.0;
        $cost = 0.0;

        foreach ($returns as $return) {
            foreach ($return->items as $returnItem) {
                $saleItem = $returnItem->saleItem;

                if (! $saleItem || (float) $saleItem->quantity <= 0) {
                    continue;
                }

                $unitRevenue = (float) $saleItem->line_total / (float) $saleItem->quantity;
                $revenue += $unitRevenue * (float) $returnItem->quantity_returned;
                $cost += (float) $saleItem->cost_at_sale * (float) $returnItem->quantity_returned;
            }
        }

        return [$revenue, $cost];
    }

    private static function summary(Collection $sales, Collection $returns, Collection $shrinkageMovements): array
    {
        $grossRevenue = (float) $sales->sum(fn ($sale) => $sale->grand_total - $sale->tax_total);
        $cogs = (float) $sales->sum(fn ($sale) => $sale->items->sum(fn ($line) => $line->cost_at_sale * $line->quantity));

        [$returnsRevenue, $returnsCogs] = static::returnedRevenueAndCost($returns);

        $netRevenue = $grossRevenue - $returnsRevenue;
        $netCogs = $cogs - $returnsCogs;
        $grossProfit = $netRevenue - $netCogs;
        $shrinkage = (float) $shrinkageMovements->sum(fn ($movement) => abs((float) $movement->quantity) * (float) $movement->unit_cost);
        $netGrossProfit = $grossProfit - $shrinkage;

        return [
            'sales_count' => $sales->count(),
            'gross_revenue' => $grossRevenue,
            'returns_revenue' => $returnsRevenue,
            'net_revenue' => $netRevenue,
            'cogs' => $cogs,
            'returns_cogs' => $returnsCogs,
            'net_cogs' => $netCogs,
            'gross_profit' => $grossProfit,
            'shrinkage' => $shrinkage,
            'net_gross_profit' => $netGrossProfit,
            'gross_margin_pct' => $netRevenue > 0 ? ($netGrossProfit / $netRevenue) * 100 : 0,
        ];
    }

    private static function dailyBreakdown(Collection $sales, Collection $returns, Collection $shrinkageMovements): array
    {
        $returnsByDate = $returns->groupBy(fn ($return) => $return->created_at->format('Y-m-d'));
        $shrinkageByDate = $shrinkageMovements->groupBy(fn ($movement) => $movement->created_at->format('Y-m-d'));

        return $sales
            ->groupBy(fn ($sale) => $sale->created_at->format('Y-m-d'))
            ->map(function ($daySales, $date) use ($returnsByDate, $shrinkageByDate) {
                $revenue = (float) $daySales->sum(fn ($sale) => $sale->grand_total - $sale->tax_total);
                $cogs = (float) $daySales->sum(fn ($sale) => $sale->items->sum(fn ($line) => $line->cost_at_sale * $line->quantity));

                [$returnsRevenue, $returnsCogs] = static::returnedRevenueAndCost($returnsByDate->get($date, collect()));
                $shrinkage = (float) $shrinkageByDate->get($date, collect())
                    ->sum(fn ($movement) => abs((float) $movement->quantity) * (float) $movement->unit_cost);

                $netRevenue = $revenue - $returnsRevenue;
                $netCogs = $cogs - $returnsCogs;

                return [
                    'date' => $date,
                    'count' => $daySales->count(),
                    'revenue' => $netRevenue,
                    'cogs' => $netCogs,
                    'shrinkage' => $shrinkage,
                    'gross_profit' => $netRevenue - $netCogs - $shrinkage,
                ];
            })
            ->sortKeysDesc()
            ->values()
            ->all();
    }

    private static function categoryBreakdown(Collection $sales, Collection $returns): array
    {
        $returnedQtyBySaleItem = collect();

        foreach ($returns as $return) {
            foreach ($return->items as $returnItem) {
                $returnedQtyBySaleItem->put(
                    $returnItem->sale_item_id,
                    $returnedQtyBySaleItem->get($returnItem->sale_item_id, 0) + (float) $returnItem->quantity_returned,
                );
            }
        }

        $lines = $sales->flatMap(fn ($sale) => $sale->items);

        return $lines
            ->groupBy(fn ($line) => $line->item?->product?->category?->name ?? __('Uncategorized'))
            ->map(function ($categoryLines, $category) use ($returnedQtyBySaleItem) {
                $revenue = 0.0;
                $cost = 0.0;

                foreach ($categoryLines as $line) {
                    $returnedQty = $returnedQtyBySaleItem->get($line->id, 0);
                    $netQty = max(0, (float) $line->quantity - $returnedQty);
                    $unitRevenue = (float) $line->quantity > 0 ? (float) $line->line_total / (float) $line->quantity : 0;

                    $revenue += $unitRevenue * $netQty;
                    $cost += (float) $line->cost_at_sale * $netQty;
                }

                return [
                    'category' => $category,
                    'revenue' => $revenue,
                    'cogs' => $cost,
                    'gross_profit' => $revenue - $cost,
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }
}
