<?php

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public const PAYMENT_METHODS = ['cash', 'mobile_money', 'card', 'credit'];

    public string $opening_float = '';
    public string $closing_float_counted = '';

    public array $lines = [];
    public array $payments = [];
    public ?int $customer_id = null;

    public ?string $lastReceiptNumber = null;
    public ?float $lastReceiptTotal = null;
    public ?float $lastExpectedCash = null;
    public ?float $lastVariance = null;

    public function mount(): void
    {
        Gate::authorize('create', Sale::class);

        if ($this->openShift) {
            $this->resetCart();
        }
    }

    #[Computed]
    public function openShift(): ?Shift
    {
        $actor = Auth::user();

        return Shift::where('cashier_id', $actor->id)
            ->where('branch_id', $actor->branch_id)
            ->where('status', 'open')
            ->first();
    }

    #[Computed]
    public function itemOptions()
    {
        return Item::with('baseUnit')->orderBy('sku')->get();
    }

    #[Computed]
    public function itemSelectOptions(): array
    {
        return $this->itemOptions
            ->map(fn (Item $item) => [
                'id' => $item->id,
                'label' => $item->sku.' ('.$item->baseUnit->abbreviation.') - '.number_format($item->selling_price, 2),
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function customerOptions()
    {
        return Customer::orderBy('name')->get();
    }

    #[Computed]
    public function customerSelectOptions(): array
    {
        return $this->customerOptions
            ->map(fn (Customer $customer) => ['id' => $customer->id, 'label' => $customer->name])
            ->values()
            ->all();
    }

    public function itemTracksBatches(?int $itemId): bool
    {
        if (! $itemId) {
            return false;
        }

        return (bool) $this->itemOptions->firstWhere('id', $itemId)?->track_batches;
    }

    public function batchOptionsForItem(?int $itemId)
    {
        if (! $itemId || ! $this->itemTracksBatches($itemId)) {
            return collect();
        }

        $actor = Auth::user();

        $batchIdsWithStock = StockLevel::where('branch_id', $actor->branch_id)
            ->where('item_id', $itemId)
            ->where('quantity_on_hand', '>', 0)
            ->whereNotNull('batch_id')
            ->pluck('batch_id');

        return Batch::whereIn('id', $batchIdsWithStock)->fefoOrder()->get();
    }

    #[Computed]
    public function linesTotal(): float
    {
        return collect($this->lines)->sum(function ($line) {
            $item = $this->itemOptions->firstWhere('id', $line['item_id']);

            if (! $item) {
                return 0;
            }

            return (float) ($line['quantity'] ?? 0) * (float) $item->selling_price;
        });
    }

    #[Computed]
    public function paymentsTotal(): float
    {
        return collect($this->payments)->sum(fn ($payment) => (float) ($payment['amount'] ?? 0));
    }

    public function addLine(): void
    {
        $this->lines[] = ['item_id' => null, 'quantity' => '', 'batch_id' => null];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function addPayment(): void
    {
        $this->payments[] = ['method' => 'cash', 'amount' => ''];
    }

    public function removePayment(int $index): void
    {
        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);
    }

    private function resetCart(): void
    {
        $this->lines = [];
        $this->payments = [];
        $this->customer_id = null;
        $this->addLine();
        $this->addPayment();
    }

    public function startShift(): void
    {
        Gate::authorize('open', Shift::class);

        $actor = Auth::user();

        $validated = $this->validate([
            'opening_float' => ['required', 'numeric', 'min:0'],
        ]);

        Shift::create([
            'tenant_id' => $actor->tenant_id,
            'branch_id' => $actor->branch_id,
            'cashier_id' => $actor->id,
            'opening_float' => $validated['opening_float'],
            'opened_at' => now(),
            'status' => 'open',
        ]);

        $this->opening_float = '';
        $this->lastReceiptNumber = null;
        $this->lastReceiptTotal = null;
        $this->lastExpectedCash = null;
        $this->lastVariance = null;
        unset($this->openShift);
        $this->resetCart();
        $this->dispatch('toast', message: __('Shift opened.'), type: 'success');
    }

    public function completeSale(): void
    {
        Gate::authorize('create', Sale::class);

        $shift = $this->openShift;

        if (! $shift) {
            throw ValidationException::withMessages([
                'lines' => __('You must have an open shift to record a sale.'),
            ]);
        }

        $actor = Auth::user();

        $validated = $this->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.batch_id' => ['nullable', 'exists:batches,id'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $linesTotalCents = (int) round($this->linesTotal * 100);
        $paymentsTotalCents = (int) round(collect($validated['payments'])->sum(fn ($p) => (float) $p['amount']) * 100);

        if ($linesTotalCents !== $paymentsTotalCents) {
            throw ValidationException::withMessages([
                'payments' => __('Payments must add up to the sale total (:total).', ['total' => number_format($this->linesTotal, 2)]),
            ]);
        }

        $creditAmount = collect($validated['payments'])
            ->filter(fn ($p) => $p['method'] === 'credit')
            ->sum(fn ($p) => (float) $p['amount']);

        $customer = $validated['customer_id'] ? Customer::find($validated['customer_id']) : null;

        if ($creditAmount > 0) {
            if (! $customer) {
                throw ValidationException::withMessages([
                    'customer_id' => __('Select a customer to use the credit payment method.'),
                ]);
            }

            if ($customer->credit_limit !== null && ($customer->credit_balance + $creditAmount) > $customer->credit_limit) {
                throw ValidationException::withMessages([
                    'customer_id' => __('This sale would exceed :name\'s credit limit.', ['name' => $customer->name]),
                ]);
            }
        }

        $resolvedLines = [];

        foreach ($validated['lines'] as $line) {
            $item = $this->itemOptions->firstWhere('id', $line['item_id']);
            $quantity = (float) $line['quantity'];
            $batchId = $line['batch_id'] ?: null;

            if (! $batchId && $item->track_batches) {
                $batchId = $this->batchOptionsForItem($item->id)->first()?->id;
            }

            $onHand = (float) StockLevel::where('branch_id', $actor->branch_id)
                ->where('item_id', $item->id)
                ->where('batch_id', $batchId)
                ->sum('quantity_on_hand');

            if ($onHand < $quantity) {
                throw ValidationException::withMessages([
                    'lines' => __('Insufficient stock for :sku (:qty currently on hand).', ['sku' => $item->sku, 'qty' => $onHand]),
                ]);
            }

            $resolvedLines[] = [
                'item' => $item,
                'batch_id' => $batchId,
                'quantity' => $quantity,
                'unit_price' => (float) $item->selling_price,
            ];
        }

        $sale = DB::transaction(function () use ($actor, $shift, $resolvedLines, $validated, $creditAmount, $customer) {
            $branch = Branch::where('id', $actor->branch_id)->lockForUpdate()->first();

            $sequence = Sale::where('branch_id', $branch->id)->count() + 1;
            $receiptNumber = sprintf('%s-%d-%06d', $branch->code, now()->year, $sequence);

            $subtotal = round(collect($resolvedLines)->sum(fn ($line) => $line['quantity'] * $line['unit_price']), 2);

            $sale = Sale::create([
                'tenant_id' => $actor->tenant_id,
                'branch_id' => $branch->id,
                'shift_id' => $shift->id,
                'cashier_id' => $actor->id,
                'customer_id' => $customer?->id,
                'receipt_number' => $receiptNumber,
                'subtotal' => $subtotal,
                'discount_total' => 0,
                'tax_total' => 0,
                'grand_total' => $subtotal,
                'status' => 'completed',
            ]);

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
            }

            foreach ($validated['payments'] as $payment) {
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

        $this->lastReceiptNumber = $sale->receipt_number;
        $this->lastReceiptTotal = (float) $sale->grand_total;

        unset($this->openShift);
        $this->resetCart();
        $this->dispatch('toast', message: __('Sale completed.'), type: 'success');
    }

    public function endShift(): void
    {
        $shift = $this->openShift;

        Gate::authorize('close', $shift);

        $validated = $this->validate([
            'closing_float_counted' => ['required', 'numeric', 'min:0'],
        ]);

        $cashTotal = (float) Payment::whereHas('sale', fn ($q) => $q->where('shift_id', $shift->id))
            ->where('payment_method', 'cash')
            ->sum('amount');

        $expectedCash = (float) $shift->opening_float + $cashTotal;
        $variance = round((float) $validated['closing_float_counted'] - $expectedCash, 2);

        $shift->update([
            'closing_float_counted' => $validated['closing_float_counted'],
            'expected_cash' => $expectedCash,
            'variance' => $variance,
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->lastExpectedCash = $expectedCash;
        $this->lastVariance = $variance;
        $this->closing_float_counted = '';
        $this->lastReceiptNumber = null;
        $this->lastReceiptTotal = null;

        unset($this->openShift);

        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-close-shift' }))");
        $this->dispatch('toast', message: __('Shift closed.'), type: 'success');
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Point of Sale') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (! $this->openShift)
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 max-w-md mx-auto space-y-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Open a Shift') }}</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Enter your starting cash float to begin selling.') }}</p>

                    @if ($lastVariance !== null)
                        <div class="p-4 bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-400 rounded-md text-sm">
                            {{ __('Previous shift closed. Expected cash: :expected, Variance: :variance', ['expected' => number_format($lastExpectedCash, 2), 'variance' => number_format($lastVariance, 2)]) }}
                        </div>
                    @endif

                    <div>
                        <x-input-label for="opening-float" :value="__('Opening Cash Float')" />
                        <x-text-input wire:model="opening_float" id="opening-float" class="block mt-1 w-full" type="number" step="0.01" min="0" />
                        <x-input-error :messages="$errors->get('opening_float')" class="mt-2" />
                    </div>

                    <x-primary-button wire:click="startShift" class="w-full justify-center">
                        {{ __('Open Shift') }}
                    </x-primary-button>
                </div>
            @else
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 flex items-center justify-between">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Shift opened') }} {{ $this->openShift->opened_at->format('d M Y, H:i') }}
                        &middot; {{ __('Float') }}: {{ number_format($this->openShift->opening_float, 2) }}
                    </div>
                    <x-danger-button type="button" x-on:click="$dispatch('open-modal', 'confirm-close-shift')">
                        {{ __('Close Shift') }}
                    </x-danger-button>
                </div>

                @if ($lastReceiptNumber)
                    <div class="p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 rounded-md text-sm">
                        {{ __('Sale completed - Receipt :receipt, Total :total', ['receipt' => $lastReceiptNumber, 'total' => number_format($lastReceiptTotal, 2)]) }}
                    </div>
                @endif

                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-6">
                    <div>
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Items') }}</h3>

                        <div class="space-y-4">
                            @foreach ($lines as $index => $line)
                                <div wire:key="line-{{ $index }}" class="border border-gray-200 dark:border-gray-700 rounded-md p-4">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Line') }} {{ $index + 1 }}</span>
                                        @if (count($lines) > 1)
                                            <button
                                                type="button"
                                                title="{{ __('Remove line') }}"
                                                aria-label="{{ __('Remove line') }}"
                                                wire:click="removeLine({{ $index }})"
                                                class="inline-flex items-center justify-center p-1.5 rounded-md text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/40"
                                            ><x-icon.trash class="h-4 w-4" /></button>
                                        @endif
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                                        <div class="sm:col-span-2">
                                            <x-input-label :value="__('Item')" />
                                            <x-searchable-select
                                                :options="$this->itemSelectOptions"
                                                :wire-model="'lines.'.$index.'.item_id'"
                                                :placeholder="__('Search items…')"
                                            />
                                            <x-input-error :messages="$errors->get('lines.'.$index.'.item_id')" class="mt-2" />
                                        </div>

                                        <div>
                                            <x-input-label :value="__('Quantity')" />
                                            <x-text-input wire:model.live="lines.{{ $index }}.quantity" class="block mt-1 w-full" type="number" step="0.001" min="0" />
                                            <x-input-error :messages="$errors->get('lines.'.$index.'.quantity')" class="mt-2" />
                                        </div>

                                        @if ($this->itemTracksBatches($line['item_id']))
                                            <div>
                                                <x-input-label :value="__('Batch (optional)')" />
                                                <select wire:model="lines.{{ $index }}.batch_id" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                                                    <option value="">{{ __('Auto (FEFO)') }}</option>
                                                    @foreach ($this->batchOptionsForItem($line['item_id']) as $batch)
                                                        <option value="{{ $batch->id }}">{{ $batch->batch_number }}</option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$errors->get('lines.'.$index.'.batch_id')" class="mt-2" />
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <x-input-error :messages="$errors->get('lines')" class="mt-2" />

                        <div class="mt-4">
                            <x-secondary-button type="button" wire:click="addLine">
                                <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                                {{ __('Add Line') }}
                            </x-secondary-button>
                        </div>
                    </div>

                    <div class="text-right text-sm text-gray-500 dark:text-gray-400 border-t border-gray-200 dark:border-gray-700 pt-4">
                        {{ __('Sale Total') }}: <span class="text-gray-900 dark:text-gray-100 font-semibold text-base">{{ number_format($this->linesTotal, 2) }}</span>
                    </div>

                    <div class="max-w-sm">
                        <x-input-label :value="__('Customer (optional, required for credit)')" />
                        <x-searchable-select
                            :options="$this->customerSelectOptions"
                            wire-model="customer_id"
                            :placeholder="__('Search customers…')"
                        />
                        <x-input-error :messages="$errors->get('customer_id')" class="mt-2" />
                    </div>

                    <div>
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Payments') }}</h3>

                        <div class="space-y-3">
                            @foreach ($payments as $index => $payment)
                                <div wire:key="payment-{{ $index }}" class="flex items-end gap-3">
                                    <div class="flex-1">
                                        <x-input-label :value="__('Method')" />
                                        <select wire:model="payments.{{ $index }}.method" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                                            @foreach (self::PAYMENT_METHODS as $method)
                                                <option value="{{ $method }}">{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="flex-1">
                                        <x-input-label :value="__('Amount')" />
                                        <x-text-input wire:model.live="payments.{{ $index }}.amount" class="block mt-1 w-full" type="number" step="0.01" min="0" />
                                    </div>
                                    @if (count($payments) > 1)
                                        <button
                                            type="button"
                                            title="{{ __('Remove payment') }}"
                                            aria-label="{{ __('Remove payment') }}"
                                            wire:click="removePayment({{ $index }})"
                                            class="inline-flex items-center justify-center p-2 rounded-md text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/40"
                                        ><x-icon.trash class="h-4 w-4" /></button>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <x-input-error :messages="$errors->get('payments')" class="mt-2" />

                        <div class="mt-4">
                            <x-secondary-button type="button" wire:click="addPayment">
                                <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                                {{ __('Add Payment') }}
                            </x-secondary-button>
                        </div>

                        <div class="text-right text-sm text-gray-500 dark:text-gray-400 mt-4">
                            {{ __('Payments Total') }}: <span class="{{ round($this->paymentsTotal, 2) === round($this->linesTotal, 2) ? 'text-gray-900 dark:text-gray-100' : 'text-red-600 dark:text-red-400' }} font-medium">{{ number_format($this->paymentsTotal, 2) }}</span>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-gray-200 dark:border-gray-700 pt-4">
                        <x-primary-button wire:click="completeSale">
                            {{ __('Complete Sale') }}
                        </x-primary-button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($this->openShift)
        <x-modal name="confirm-close-shift" focusable>
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Close this shift?') }}</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Enter the counted cash in the drawer. The system will compute the expected cash and variance.') }}</p>

                <div class="mt-4">
                    <x-input-label for="closing-float" :value="__('Counted Cash')" />
                    <x-text-input wire:model="closing_float_counted" id="closing-float" class="block mt-1 w-full" type="number" step="0.01" min="0" />
                    <x-input-error :messages="$errors->get('closing_float_counted')" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                    <x-danger-button wire:click="endShift">{{ __('Close Shift') }}</x-danger-button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
