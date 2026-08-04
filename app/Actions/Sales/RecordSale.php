<?php

namespace App\Actions\Sales;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\SaleTaxCalculator;
use Illuminate\Support\Facades\DB;

class RecordSale
{
    /**
     * @param  array<int, array{item: \App\Models\Item, batch_id: ?int, quantity: float, unit_price: float}>  $resolvedLines
     * @param  array<int, array{method: string, amount: float}>  $payments
     */
    public function __invoke(
        User $actor,
        Shift $shift,
        array $resolvedLines,
        array $payments,
        ?Customer $customer,
        float $creditAmount,
        string $syncSource,
        ?string $uuid = null,
    ): Sale {
        return DB::transaction(function () use ($actor, $shift, $resolvedLines, $payments, $customer, $creditAmount, $syncSource, $uuid) {
            $branch = Branch::where('id', $actor->branch_id)->lockForUpdate()->first();

            $sequence = Sale::where('branch_id', $branch->id)->count() + 1;
            $receiptNumber = sprintf('%s-%d-%06d', $branch->code, now()->year, $sequence);

            $subtotal = round(collect($resolvedLines)->sum(fn ($line) => $line['quantity'] * $line['unit_price']), 2);
            $taxTotal = SaleTaxCalculator::calculate($actor->tenant_id, $resolvedLines)['total'];

            $sale = Sale::create([
                'uuid' => $uuid,
                'tenant_id' => $actor->tenant_id,
                'branch_id' => $branch->id,
                'shift_id' => $shift->id,
                'cashier_id' => $actor->id,
                'customer_id' => $customer?->id,
                'receipt_number' => $receiptNumber,
                'subtotal' => $subtotal,
                'discount_total' => 0,
                'tax_total' => $taxTotal,
                'grand_total' => round($subtotal + $taxTotal, 2),
                'status' => 'completed',
                'sync_source' => $syncSource,
                'synced_at' => now(),
            ]);

            $oversold = false;

            foreach ($resolvedLines as $line) {
                $sale->items()->create([
                    'item_id' => $line['item']->id,
                    'batch_id' => $line['batch_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => 0,
                    'line_total' => round($line['quantity'] * $line['unit_price'], 2),
                    'cost_at_sale' => $line['item']->cost_price,
                ]);

                StockMovement::create([
                    'tenant_id' => $actor->tenant_id,
                    'branch_id' => $branch->id,
                    'item_id' => $line['item']->id,
                    'batch_id' => $line['batch_id'],
                    'movement_type' => 'sale',
                    'quantity' => -$line['quantity'],
                    'unit_cost' => $line['item']->cost_price,
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->id,
                    'performed_by' => $actor->id,
                ]);

                $stockLevel = StockLevel::firstOrCreate(
                    [
                        'tenant_id' => $actor->tenant_id,
                        'branch_id' => $branch->id,
                        'item_id' => $line['item']->id,
                        'batch_id' => $line['batch_id'],
                    ],
                    ['quantity_on_hand' => 0],
                );
                $stockLevel->decrement('quantity_on_hand', $line['quantity']);

                if ($stockLevel->fresh()->quantity_on_hand < 0) {
                    $oversold = true;
                }
            }

            if ($oversold && $syncSource === 'offline') {
                $sale->update(['offline_flagged_oversell' => true]);
            }

            foreach ($payments as $payment) {
                $sale->payments()->create([
                    'payment_method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'reference_number' => null,
                ]);
            }

            if ($creditAmount > 0) {
                $newBalance = round((float) $customer->credit_balance + $creditAmount, 2);

                CustomerLedgerEntry::create([
                    'tenant_id' => $actor->tenant_id,
                    'customer_id' => $customer->id,
                    'entry_type' => 'debit_sale',
                    'amount' => $creditAmount,
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->id,
                    'balance_after' => $newBalance,
                ]);

                $customer->update(['credit_balance' => $newBalance]);
            }

            return $sale;
        });
    }
}
