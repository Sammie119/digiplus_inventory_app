<?php

use App\Actions\Sales\RecordSale;
use App\Mail\ShiftClosedSummaryMail;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockLevel;
use App\Support\SaleTaxCalculator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
    public ?string $lastSaleUuid = null;
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
                'code' => $item->barcode,
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

    private function resolveSaleLine(Item $item, float $quantity, ?int $batchId): array
    {
        if (! $batchId && $item->track_batches) {
            $batchId = $this->batchOptionsForItem($item->id)->first()?->id;
        }

        return [
            'item' => $item,
            'batch_id' => $batchId,
            'quantity' => $quantity,
            'unit_price' => (float) $item->selling_price,
        ];
    }

    #[Computed]
    public function onHandByItem()
    {
        $actor = Auth::user();

        return StockLevel::where('branch_id', $actor->branch_id)
            ->selectRaw('item_id, sum(quantity_on_hand) as total')
            ->groupBy('item_id')
            ->pluck('total', 'item_id');
    }

    #[Computed]
    public function offlineCatalog(): array
    {
        return $this->itemOptions
            ->map(fn (Item $item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'barcode' => $item->barcode,
                'label' => $item->sku.' ('.$item->baseUnit->abbreviation.')',
                'price' => (float) $item->selling_price,
                'on_hand' => (float) ($this->onHandByItem[$item->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    public function syncOfflineSale(array $payload): void
    {
        Gate::authorize('create', Sale::class);

        if (! empty($payload['uuid']) && Sale::where('uuid', $payload['uuid'])->exists()) {
            return;
        }

        $shift = $this->openShift;

        if (! $shift) {
            throw ValidationException::withMessages([
                'lines' => __('No open shift to record this offline sale against.'),
            ]);
        }

        $actor = Auth::user();

        // Offline sales don't support credit (no customer/credit-limit checks
        // are safe against stale cached data) or a specific batch (FEFO is
        // resolved server-side at sync time, same as the online fallback).
        $validated = validator($payload, [
            'uuid' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', Rule::in(array_diff(self::PAYMENT_METHODS, ['credit']))],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
        ])->validate();

        $resolvedLines = [];

        foreach ($validated['lines'] as $line) {
            $item = $this->itemOptions->firstWhere('id', $line['item_id']);

            if ($item) {
                $resolvedLines[] = $this->resolveSaleLine($item, (float) $line['quantity'], null);
            }
        }

        if (empty($resolvedLines)) {
            throw ValidationException::withMessages([
                'lines' => __('None of the items in this offline sale could be found.'),
            ]);
        }

        $sale = app(RecordSale::class)($actor, $shift, $resolvedLines, $validated['payments'], null, 0.0, 'offline', $validated['uuid'] ?? null);

        $this->dispatch(
            'toast',
            message: $sale->offline_flagged_oversell
                ? __('Offline sale synced (:receipt) — flagged for stock review.', ['receipt' => $sale->receipt_number])
                : __('Offline sale synced (:receipt).', ['receipt' => $sale->receipt_number]),
            type: $sale->offline_flagged_oversell ? 'error' : 'success',
        );
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

    private function resolvedCartLines(): array
    {
        return collect($this->lines)
            ->map(function ($line) {
                $item = $this->itemOptions->firstWhere('id', $line['item_id']);

                return $item ? ['item' => $item, 'quantity' => (float) ($line['quantity'] ?? 0)] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    #[Computed]
    public function taxCalculation(): array
    {
        return SaleTaxCalculator::calculate(Auth::user()->tenant_id, $this->resolvedCartLines());
    }

    #[Computed]
    public function taxBreakdown(): array
    {
        return $this->taxCalculation['breakdown'];
    }

    #[Computed]
    public function taxTotal(): float
    {
        return $this->taxCalculation['total'];
    }

    #[Computed]
    public function grandTotal(): float
    {
        return round($this->linesTotal + $this->taxTotal, 2);
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

        // Invalidate Livewire's per-request computed cache: completeSale()
        // reads these (via grandTotal, for the payment-matching check)
        // before the cart is reset, so without this the post-sale render
        // would show the previous sale's totals against an empty cart.
        unset($this->linesTotal, $this->taxCalculation, $this->taxBreakdown, $this->taxTotal, $this->grandTotal);
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
        $this->lastSaleUuid = null;
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

        $grandTotalCents = (int) round($this->grandTotal * 100);
        $paymentsTotalCents = (int) round(collect($validated['payments'])->sum(fn ($p) => (float) $p['amount']) * 100);

        if ($grandTotalCents !== $paymentsTotalCents) {
            throw ValidationException::withMessages([
                'payments' => __('Payments must add up to the sale total (:total).', ['total' => number_format($this->grandTotal, 2)]),
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
            $resolvedLine = $this->resolveSaleLine($item, (float) $line['quantity'], $line['batch_id'] ?: null);

            $onHand = (float) StockLevel::where('branch_id', $actor->branch_id)
                ->where('item_id', $resolvedLine['item']->id)
                ->where('batch_id', $resolvedLine['batch_id'])
                ->sum('quantity_on_hand');

            if ($onHand < $resolvedLine['quantity']) {
                throw ValidationException::withMessages([
                    'lines' => __('Insufficient stock for :sku (:qty currently on hand).', ['sku' => $item->sku, 'qty' => $onHand]),
                ]);
            }

            $resolvedLines[] = $resolvedLine;
        }

        $sale = app(RecordSale::class)($actor, $shift, $resolvedLines, $validated['payments'], $customer, $creditAmount, 'online');

        $this->lastReceiptNumber = $sale->receipt_number;
        $this->lastReceiptTotal = (float) $sale->grand_total;
        $this->lastSaleUuid = $sale->uuid;

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

        $this->sendShiftClosedSummary($shift, $expectedCash, $variance, (float) $validated['closing_float_counted']);

        $this->lastExpectedCash = $expectedCash;
        $this->lastVariance = $variance;
        $this->closing_float_counted = '';
        $this->lastReceiptNumber = null;
        $this->lastReceiptTotal = null;
        $this->lastSaleUuid = null;

        unset($this->openShift);

        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-close-shift' }))");
        $this->dispatch('toast', message: __('Shift closed.'), type: 'success');
    }

    private function sendShiftClosedSummary(Shift $shift, float $expectedCash, float $variance, float $closingFloatCounted): void
    {
        try {
            $owners = $shift->cashier->tenant->users()
                ->whereHas('roles', fn ($q) => $q->where('name', 'Business Owner'))
                ->whereNotNull('email_verified_at')
                ->get();

            if ($owners->isEmpty()) {
                return;
            }

            $paymentTotals = Payment::whereHas('sale', fn ($q) => $q->where('shift_id', $shift->id))
                ->selectRaw('payment_method, SUM(amount) as total')
                ->groupBy('payment_method')
                ->pluck('total', 'payment_method');

            $completedSales = Sale::where('shift_id', $shift->id)->where('status', 'completed');

            $summary = [
                'cashier_name' => $shift->cashier->name,
                'branch_name' => $shift->branch->name,
                'opened_at' => $shift->opened_at,
                'closed_at' => $shift->closed_at,
                'sales_count' => $completedSales->count(),
                'total_sales' => (float) $completedSales->sum('grand_total'),
                'cash_total' => (float) ($paymentTotals['cash'] ?? 0),
                'mobile_money_total' => (float) ($paymentTotals['mobile_money'] ?? 0),
                'card_total' => (float) ($paymentTotals['card'] ?? 0),
                'credit_total' => (float) ($paymentTotals['credit'] ?? 0),
                'opening_float' => (float) $shift->opening_float,
                'expected_cash' => $expectedCash,
                'closing_float_counted' => $closingFloatCounted,
                'variance' => $variance,
            ];

            foreach ($owners as $owner) {
                Mail::to($owner->email)->send(new ShiftClosedSummaryMail($shift, $summary));
            }
        } catch (\Throwable $e) {
            Log::error('Shift closed summary email failed', ['shift_id' => $shift->id, 'exception' => $e]);
        }
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
                <div
                    x-data="{
                        online: navigator.onLine,
                        catalog: @js($this->offlineCatalog),
                        queue: [],
                        offlineLines: [{ item_id: null, quantity: '', search: '' }],
                        offlinePayments: [{ method: 'cash', amount: '' }],
                        init() {
                            this.queue = JSON.parse(localStorage.getItem('pos_offline_queue') || '[]');
                            window.addEventListener('online', () => { this.online = true; this.syncQueue(); });
                            window.addEventListener('offline', () => { this.online = false; });
                            if (this.online) this.syncQueue();
                        },
                        persistQueue() {
                            localStorage.setItem('pos_offline_queue', JSON.stringify(this.queue));
                        },
                        filteredCatalog(search) {
                            if (! search) return this.catalog.slice(0, 20);
                            const term = search.toLowerCase();
                            return this.catalog.filter(o => o.label.toLowerCase().includes(term) || (o.barcode && o.barcode.toLowerCase() === term)).slice(0, 20);
                        },
                        addOfflineLine() {
                            this.offlineLines.push({ item_id: null, quantity: '', search: '' });
                        },
                        removeOfflineLine(index) {
                            this.offlineLines.splice(index, 1);
                        },
                        addOfflinePayment() {
                            this.offlinePayments.push({ method: 'cash', amount: '' });
                        },
                        removeOfflinePayment(index) {
                            this.offlinePayments.splice(index, 1);
                        },
                        offlineSubtotal() {
                            return this.offlineLines.reduce((total, line) => {
                                const item = this.catalog.find(o => o.id === line.item_id);
                                return total + (item ? item.price * (parseFloat(line.quantity) || 0) : 0);
                            }, 0);
                        },
                        queueOfflineSale() {
                            const lines = this.offlineLines.filter(l => l.item_id && parseFloat(l.quantity) > 0);
                            const payments = this.offlinePayments.filter(p => parseFloat(p.amount) > 0);

                            if (! lines.length || ! payments.length) {
                                window.dispatchEvent(new CustomEvent('toast', { detail: { message: '{{ __('Add at least one item and one payment.') }}', type: 'error' } }));
                                return;
                            }

                            this.queue.push({
                                uuid: crypto.randomUUID(),
                                lines: lines.map(l => ({ item_id: l.item_id, quantity: parseFloat(l.quantity) })),
                                payments: payments.map(p => ({ method: p.method, amount: parseFloat(p.amount) })),
                            });
                            this.persistQueue();

                            this.offlineLines = [{ item_id: null, quantity: '', search: '' }];
                            this.offlinePayments = [{ method: 'cash', amount: '' }];

                            window.dispatchEvent(new CustomEvent('toast', { detail: { message: '{{ __('Sale queued — will sync when back online.') }}', type: 'success' } }));
                        },
                        async syncQueue() {
                            const remaining = [];

                            for (const sale of this.queue) {
                                try {
                                    await this.$wire.call('syncOfflineSale', sale);
                                } catch (e) {
                                    remaining.push(sale);
                                }
                            }

                            this.queue = remaining;
                            this.persistQueue();
                        },
                    }"
                    x-init="init()"
                    class="space-y-4"
                >
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 flex items-center justify-between">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Shift opened') }} {{ $this->openShift->opened_at->format('d M Y, H:i') }}
                        &middot; {{ __('Float') }}: {{ number_format($this->openShift->opening_float, 2) }}
                    </div>
                    <x-danger-button type="button" x-on:click="$dispatch('open-modal', 'confirm-close-shift')">
                        {{ __('Close Shift') }}
                    </x-danger-button>
                </div>

                <div x-show="!online" class="p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 rounded-md text-sm">
                    {{ __('Offline — sales will be queued on this device and synced automatically once the connection returns.') }}
                    <span x-show="queue.length" x-text="`(${queue.length} queued)`"></span>
                </div>

                <div x-show="online && queue.length" class="p-4 bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-400 rounded-md text-sm">
                    {{ __('Back online — syncing queued offline sales…') }} <span x-text="`(${queue.length} remaining)`"></span>
                </div>

                @if ($lastReceiptNumber)
                    <div class="p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 rounded-md text-sm flex items-center justify-between gap-4">
                        <span>{{ __('Sale completed - Receipt :receipt, Total :total', ['receipt' => $lastReceiptNumber, 'total' => number_format($lastReceiptTotal, 2)]) }}</span>
                        @if ($lastSaleUuid)
                            <a href="{{ route('sales.receipt', $lastSaleUuid) }}" target="_blank" class="shrink-0 font-medium text-green-800 dark:text-green-300 underline hover:text-green-900 dark:hover:text-green-200">
                                {{ __('Print Receipt') }}
                            </a>
                        @endif
                    </div>
                @endif

                <template x-if="online">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-6">
                    <div>
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Items') }}</h3>

                        <div class="hidden sm:grid sm:grid-cols-12 gap-3 px-1">
                            <div class="sm:col-span-7"><x-input-label :value="__('Item')" /></div>
                            <div class="sm:col-span-1"><x-input-label :value="__('Stock')" /></div>
                            <div class="sm:col-span-2"><x-input-label :value="__('Quantity')" /></div>
                            <div class="sm:col-span-1"><x-input-label :value="__('Total')" /></div>
                            <div class="sm:col-span-1"></div>
                        </div>

                        <div class="space-y-4 mt-1">
                            @foreach ($lines as $index => $line)
                                <div wire:key="line-{{ $index }}" class="border border-gray-200 dark:border-gray-700 rounded-md p-3">
                                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 sm:items-center">
                                        <div class="sm:col-span-7">
                                            <x-input-label :value="__('Item')" class="sm:hidden" />
                                            <x-searchable-select
                                                :options="$this->itemSelectOptions"
                                                :wire-model="'lines.'.$index.'.item_id'"
                                                :placeholder="__('Search items…')"
                                            />
                                            <x-input-error :messages="$errors->get('lines.'.$index.'.item_id')" class="mt-2" />
                                        </div>

                                        <div class="sm:col-span-1">
                                            <x-input-label :value="__('Stock')" class="sm:hidden" />
                                            <p class="mt-1 sm:mt-0 py-2 text-sm text-gray-900 dark:text-gray-100">
                                                {{ rtrim(rtrim(number_format($this->onHandByItem[$line['item_id']] ?? 0, 3), '0'), '.') }}
                                            </p>
                                        </div>

                                        <div class="sm:col-span-2">
                                            <x-input-label :value="__('Quantity')" class="sm:hidden" />
                                            <x-text-input wire:model.live="lines.{{ $index }}.quantity" class="block mt-1 sm:mt-0 w-full" type="number" step="0.001" min="0" />
                                            <x-input-error :messages="$errors->get('lines.'.$index.'.quantity')" class="mt-2" />
                                        </div>

                                        <div class="sm:col-span-1">
                                            <x-input-label :value="__('Total')" class="sm:hidden" />
                                            <p class="mt-1 sm:mt-0 py-2 text-sm text-gray-900 dark:text-gray-100">
                                                {{ number_format((float) ($line['quantity'] ?? 0) * (float) optional($this->itemOptions->firstWhere('id', $line['item_id']))->selling_price, 2) }}
                                            </p>
                                        </div>

                                        <div class="sm:col-span-1 flex justify-end sm:justify-center">
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

                    <div class="text-right text-sm text-gray-500 dark:text-gray-400 border-t border-gray-200 dark:border-gray-700 pt-4 space-y-1">
                        <div>
                            {{ __('Subtotal') }}: <span class="text-gray-900 dark:text-gray-100 font-medium">{{ number_format($this->linesTotal, 2) }}</span>
                        </div>
                        @foreach ($this->taxBreakdown as $tax)
                            <div>
                                {{ $tax['name'] }} ({{ rtrim(rtrim(number_format($tax['rate'], 2), '0'), '.') }}%): <span class="text-gray-900 dark:text-gray-100 font-medium">{{ number_format($tax['amount'], 2) }}</span>
                            </div>
                        @endforeach
                        <div>
                            {{ __('Grand Total') }}: <span class="text-gray-900 dark:text-gray-100 font-semibold text-base">{{ number_format($this->grandTotal, 2) }}</span>
                        </div>
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
                            {{ __('Payments Total') }}: <span class="{{ round($this->paymentsTotal, 2) === round($this->grandTotal, 2) ? 'text-gray-900 dark:text-gray-100' : 'text-red-600 dark:text-red-400' }} font-medium">{{ number_format($this->paymentsTotal, 2) }}</span>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-gray-200 dark:border-gray-700 pt-4">
                        <x-primary-button wire:click="completeSale">
                            {{ __('Complete Sale') }}
                        </x-primary-button>
                    </div>
                </div>
                </template>

                <template x-if="!online">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-6">
                    <div>
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Offline Sale') }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('Prices are from the last-known cached catalog and exclude tax — the final total is confirmed once this sale syncs.') }}</p>

                        <div class="space-y-4">
                            <template x-for="(line, index) in offlineLines" :key="index">
                                <div class="border border-gray-200 dark:border-gray-700 rounded-md p-4">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400" x-text="'{{ __('Line') }} ' + (index + 1)"></span>
                                        <button type="button" x-show="offlineLines.length > 1" x-on:click="removeOfflineLine(index)" class="inline-flex items-center justify-center p-1.5 rounded-md text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/40">
                                            <x-icon.trash class="h-4 w-4" />
                                        </button>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                        <div class="sm:col-span-2 relative" x-data="{ open: false }">
                                            <x-input-label :value="__('Item')" />
                                            <input
                                                type="text"
                                                x-model="line.search"
                                                x-on:focus="open = true"
                                                x-on:input="open = true; line.item_id = null"
                                                x-on:click.outside="open = false"
                                                autocomplete="off"
                                                placeholder="{{ __('Search cached catalog…') }}"
                                                class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full"
                                            />
                                            <div x-show="open" x-transition style="display: none;" class="absolute z-20 mt-1 w-full max-h-60 overflow-auto bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg py-1 text-sm">
                                                <template x-for="option in filteredCatalog(line.search)" :key="option.id">
                                                    <div
                                                        x-on:click="line.item_id = option.id; line.search = option.label; open = false"
                                                        x-text="option.label + ' — ' + option.price.toFixed(2) + ' (' + option.on_hand + ' on hand)'"
                                                        class="px-3 py-1.5 cursor-pointer hover:bg-indigo-50 dark:hover:bg-indigo-900/40 text-gray-900 dark:text-gray-100"
                                                    ></div>
                                                </template>
                                                <div x-show="filteredCatalog(line.search).length === 0" class="px-3 py-1.5 text-gray-400 dark:text-gray-500">{{ __('No matches in cached catalog') }}</div>
                                            </div>
                                        </div>

                                        <div>
                                            <x-input-label :value="__('Quantity')" />
                                            <input type="number" step="0.001" min="0" x-model="line.quantity" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full" />
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="mt-4">
                            <x-secondary-button type="button" x-on:click="addOfflineLine()">
                                <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                                {{ __('Add Line') }}
                            </x-secondary-button>
                        </div>
                    </div>

                    <div class="text-right text-sm text-gray-500 dark:text-gray-400 border-t border-gray-200 dark:border-gray-700 pt-4">
                        {{ __('Estimated Subtotal (excl. tax)') }}: <span class="text-gray-900 dark:text-gray-100 font-medium" x-text="offlineSubtotal().toFixed(2)"></span>
                    </div>

                    <div>
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Payments') }}</h3>

                        <div class="space-y-3">
                            <template x-for="(payment, index) in offlinePayments" :key="index">
                                <div class="flex items-end gap-3">
                                    <div class="flex-1">
                                        <x-input-label :value="__('Method')" />
                                        <select x-model="payment.method" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                                            @foreach (array_diff(self::PAYMENT_METHODS, ['credit']) as $method)
                                                <option value="{{ $method }}">{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="flex-1">
                                        <x-input-label :value="__('Amount')" />
                                        <input type="number" step="0.01" min="0" x-model="payment.amount" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full" />
                                    </div>
                                    <button type="button" x-show="offlinePayments.length > 1" x-on:click="removeOfflinePayment(index)" class="inline-flex items-center justify-center p-2 rounded-md text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/40">
                                        <x-icon.trash class="h-4 w-4" />
                                    </button>
                                </div>
                            </template>
                        </div>

                        <div class="mt-4">
                            <x-secondary-button type="button" x-on:click="addOfflinePayment()">
                                <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                                {{ __('Add Payment') }}
                            </x-secondary-button>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-gray-200 dark:border-gray-700 pt-4">
                        <x-primary-button type="button" x-on:click="queueOfflineSale()">
                            {{ __('Record Offline Sale (Queued)') }}
                        </x-primary-button>
                    </div>
                </div>
                </template>
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
