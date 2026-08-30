<?php

namespace App\Support;

use App\Models\TaxRate;

class SaleTaxCalculator
{
    /**
     * @param  array<int, array{item: \App\Models\Item, quantity: float}>  $lines
     */
    public static function calculate(int $tenantId, array $lines): array
    {
        $rates = TaxRate::where('tenant_id', $tenantId)->get();
        $breakdown = [];

        foreach ($lines as $line) {
            $item = $line['item'];
            $subtotal = (float) $line['quantity'] * (float) $item->selling_price;
            $categoryId = $item->product?->category_id;

            foreach ($rates as $rate) {
                $applies = $rate->applies_to === 'all'
                    || ($rate->applies_to === 'category' && $categoryId !== null && $rate->category_id === $categoryId);

                if (! $applies) {
                    continue;
                }

                $breakdown[$rate->id] ??= [
                    'tax_rate_id' => $rate->id,
                    'name' => $rate->name,
                    'rate' => (float) $rate->rate_percentage,
                    'amount' => 0.0,
                ];
                $breakdown[$rate->id]['amount'] += round($subtotal * (float) $rate->rate_percentage / 100, 2);
            }
        }

        $breakdown = array_values($breakdown);

        return [
            'breakdown' => $breakdown,
            'total' => array_sum(array_column($breakdown, 'amount')),
        ];
    }
}
