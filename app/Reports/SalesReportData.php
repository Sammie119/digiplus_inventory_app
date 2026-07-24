<?php

namespace App\Reports;

use App\Models\Sale;

class SalesReportData
{
    public static function build(int $tenantId, ?int $branchId, string $from, string $to): array
    {
        $query = Sale::with('items')
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $sales = $query->get();

        return [
            'summary' => static::summary($sales),
            'dailyBreakdown' => static::dailyBreakdown($sales),
        ];
    }

    private static function summary($sales): array
    {
        $revenue = (float) $sales->sum('grand_total');
        $tax = (float) $sales->sum('tax_total');
        $cost = (float) $sales->sum(fn ($sale) => $sale->items->sum(fn ($line) => $line->cost_at_sale * $line->quantity));
        $margin = $revenue - $cost;

        return [
            'count' => $sales->count(),
            'revenue' => $revenue,
            'tax' => $tax,
            'cost' => $cost,
            'margin' => $margin,
            'margin_pct' => $revenue > 0 ? ($margin / $revenue) * 100 : 0,
        ];
    }

    private static function dailyBreakdown($sales): array
    {
        return $sales
            ->groupBy(fn ($sale) => $sale->created_at->format('Y-m-d'))
            ->map(function ($daySales, $date) {
                $revenue = (float) $daySales->sum('grand_total');
                $cost = (float) $daySales->sum(fn ($sale) => $sale->items->sum(fn ($line) => $line->cost_at_sale * $line->quantity));

                return [
                    'date' => $date,
                    'count' => $daySales->count(),
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'margin' => $revenue - $cost,
                ];
            })
            ->sortKeysDesc()
            ->values()
            ->all();
    }
}
