<?php

use App\Models\CustomerLedgerEntry;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $voidingSaleId = null;
    public string $voidReason = '';

    public ?int $returningSaleId = null;
    public string $returnReason = '';
    public array $returnLines = [];

    public function mount(): void
    {
        Gate::authorize('viewAny', Sale::class);
    }

    #[Computed]
    public function sales()
    {
        $actor = Auth::user();

        $query = Sale::with(['branch', 'cashier', 'customer', 'items.item', 'payments', 'returns.items.saleItem.item'])->latest();

        if (! $actor->hasRole('Business Owner')) {
            $query->where('branch_id', $actor->branch_id);
        }

        return $query->get();
    }

    public function openVoidModal(int $saleId): void
    {
        $sale = Sale::findOrFail($saleId);
        Gate::authorize('requestVoid', $sale);

        $this->voidingSaleId = $saleId;
        $this->voidReason = '';
        $this->resetErrorBag();
    }

    public function requestVoid(): void
    {
        $sale = Sale::findOrFail($this->voidingSaleId);
        Gate::authorize('requestVoid', $sale);

        $validated = $this->validate([
            'voidReason' => ['required', 'string', 'max:255'],
        ]);

        $sale->update([
            'voided_by' => Auth::id(),
            'void_reason' => $validated['voidReason'],
        ]);

        $saleId = $this->voidingSaleId;
        $this->voidingSaleId = null;
        unset($this->sales);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'void-sale-{$saleId}' }))");
        $this->dispatch('toast', message: __('Void requested — pending approval.'), type: 'success');
    }

    public function resolveVoid(int $saleId, bool $approve): void
    {
        $sale = Sale::with(['items.item', 'payments', 'customer'])->findOrFail($saleId);
        Gate::authorize('resolveVoid', $sale);

        $actor = Auth::user();

        if ($approve) {
            DB::transaction(function () use ($sale, $actor) {
                foreach ($sale->items as $line) {
                    StockMovement::create([
                        'tenant_id' => $actor->tenant_id,
                        'branch_id' => $sale->branch_id,
                        'item_id' => $line->item_id,
                        'batch_id' => $line->batch_id,
                        'movement_type' => 'return_in',
                        'quantity' => $line->quantity,
                        'unit_cost' => $line->cost_at_sale,
                        'reference_type' => Sale::class,
                        'reference_id' => $sale->id,
                        'performed_by' => $actor->id,
                    ]);

                    $stockLevel = StockLevel::firstOrCreate(
                        [
                            'tenant_id' => $actor->tenant_id,
                            'branch_id' => $sale->branch_id,
                            'item_id' => $line->item_id,
                            'batch_id' => $line->batch_id,
                        ],
                        ['quantity_on_hand' => 0],
                    );
                    $stockLevel->increment('quantity_on_hand', $line->quantity);
                }

                $creditAmount = (float) $sale->payments->where('payment_method', 'credit')->sum('amount');

                if ($creditAmount > 0 && $sale->customer) {
                    $newBalance = round((float) $sale->customer->credit_balance - $creditAmount, 2);

                    CustomerLedgerEntry::create([
                        'tenant_id' => $actor->tenant_id,
                        'customer_id' => $sale->customer->id,
                        'entry_type' => 'void_reversal',
                        'amount' => $creditAmount,
                        'reference_type' => Sale::class,
                        'reference_id' => $sale->id,
                        'balance_after' => $newBalance,
                    ]);

                    $sale->customer->update(['credit_balance' => $newBalance]);
                }

                $sale->update(['status' => 'void', 'void_approved_by' => $actor->id]);
            });
        } else {
            $sale->update(['voided_by' => null, 'void_reason' => null]);
        }

        unset($this->sales);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'resolve-void-{$saleId}' }))");
        $this->dispatch('toast', message: $approve ? __('Void approved.') : __('Void rejected.'), type: 'success');
    }

    public function openReturnModal(int $saleId): void
    {
        $sale = Sale::with('items.item')->findOrFail($saleId);
        Gate::authorize('create', [SaleReturn::class, $sale]);

        $this->returningSaleId = $saleId;
        $this->returnReason = '';
        $this->returnLines = $sale->items->map(fn ($item) => [
            'sale_item_id' => $item->id,
            'sku' => $item->item->sku,
            'quantity_sold' => (string) $item->quantity,
            'remaining' => (string) $item->remainingReturnableQuantity(),
            'quantity_returned' => '0',
            'restock' => true,
        ])->all();
        $this->resetErrorBag();
    }

    public function requestReturn(): void
    {
        $sale = Sale::with('items.item')->findOrFail($this->returningSaleId);
        Gate::authorize('create', [SaleReturn::class, $sale]);

        $actor = Auth::user();

        $this->validate([
            'returnReason' => ['required', 'string', 'max:255'],
            'returnLines.*.quantity_returned' => ['required', 'numeric', 'min:0'],
        ]);

        $linesToReturn = collect($this->returnLines)->filter(fn ($line) => (float) $line['quantity_returned'] > 0);

        if ($linesToReturn->isEmpty()) {
            throw ValidationException::withMessages([
                'returnLines' => __('Enter a quantity for at least one item.'),
            ]);
        }

        foreach ($linesToReturn as $line) {
            $saleItem = $sale->items->firstWhere('id', $line['sale_item_id']);

            if ((float) $line['quantity_returned'] > $saleItem->remainingReturnableQuantity()) {
                throw ValidationException::withMessages([
                    'returnLines' => __('Cannot return more than the remaining quantity for :sku.', ['sku' => $saleItem->item->sku]),
                ]);
            }
        }

        DB::transaction(function () use ($sale, $actor, $linesToReturn) {
            $saleReturn = SaleReturn::create([
                'tenant_id' => $actor->tenant_id,
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'processed_by' => $actor->id,
                'reason' => $this->returnReason,
                'status' => 'pending',
            ]);

            foreach ($linesToReturn as $line) {
                $saleReturn->items()->create([
                    'sale_item_id' => $line['sale_item_id'],
                    'quantity_returned' => $line['quantity_returned'],
                    'restock' => (bool) $line['restock'],
                ]);
            }
        });

        $saleId = $this->returningSaleId;
        $this->returningSaleId = null;
        unset($this->sales);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'return-sale-{$saleId}' }))");
        $this->dispatch('toast', message: __('Return requested — pending approval.'), type: 'success');
    }

    public function resolveReturn(int $returnId, bool $approve): void
    {
        $saleReturn = SaleReturn::with('items.saleItem.item')->findOrFail($returnId);
        Gate::authorize('resolve', $saleReturn);

        $actor = Auth::user();

        if ($approve) {
            DB::transaction(function () use ($saleReturn, $actor) {
                foreach ($saleReturn->items as $returnItem) {
                    if (! $returnItem->restock) {
                        continue;
                    }

                    $saleItem = $returnItem->saleItem;

                    StockMovement::create([
                        'tenant_id' => $actor->tenant_id,
                        'branch_id' => $saleReturn->branch_id,
                        'item_id' => $saleItem->item_id,
                        'batch_id' => $saleItem->batch_id,
                        'movement_type' => 'return_in',
                        'quantity' => $returnItem->quantity_returned,
                        'unit_cost' => $saleItem->cost_at_sale,
                        'reference_type' => SaleReturn::class,
                        'reference_id' => $saleReturn->id,
                        'performed_by' => $actor->id,
                    ]);

                    $stockLevel = StockLevel::firstOrCreate(
                        [
                            'tenant_id' => $actor->tenant_id,
                            'branch_id' => $saleReturn->branch_id,
                            'item_id' => $saleItem->item_id,
                            'batch_id' => $saleItem->batch_id,
                        ],
                        ['quantity_on_hand' => 0],
                    );
                    $stockLevel->increment('quantity_on_hand', $returnItem->quantity_returned);
                }

                $saleReturn->update(['status' => 'approved']);
            });
        } else {
            $saleReturn->delete();
        }

        unset($this->sales);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'resolve-return-{$returnId}' }))");
        $this->dispatch('toast', message: $approve ? __('Return approved.') : __('Return rejected.'), type: 'success');
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Sales') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->sales as $sale)
                    <div wire:key="sale-card-{{ $sale->id }}" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $sale->receipt_number }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ $sale->branch->name }} &middot; {{ $sale->cashier->name }}</div>
                            </div>
                            <x-sale-status-badge :sale="$sale" />
                        </div>
                        <div class="mt-3 flex items-center justify-between">
                            <span class="text-sm text-gray-900 dark:text-gray-100 font-medium">{{ number_format($sale->grand_total, 2) }}</span>
                            <button type="button" x-on:click="$dispatch('open-modal', 'sale-view-{{ $sale->id }}')" class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View') }}</button>
                        </div>
                    </div>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No sales yet.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Receipt') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Branch') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Cashier') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Total') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Date') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->sales as $sale)
                            <tr wire:key="sale-{{ $sale->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $sale->receipt_number }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $sale->branch->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $sale->cashier->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm"><x-sale-status-badge :sale="$sale" /></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($sale->grand_total, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $sale->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-2">
                                    <button type="button" x-on:click="$dispatch('open-modal', 'sale-view-{{ $sale->id }}')" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View') }}</button>

                                    @can('requestVoid', $sale)
                                        <button type="button" wire:click="openVoidModal({{ $sale->id }})" x-on:click="$dispatch('open-modal', 'void-sale-{{ $sale->id }}')" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Void') }}</button>
                                    @endcan

                                    @can('resolveVoid', $sale)
                                        <button type="button" x-on:click="$dispatch('open-modal', 'approve-void-{{ $sale->id }}')" class="text-green-600 dark:text-green-400 hover:text-green-900 dark:hover:text-green-300">{{ __('Approve Void') }}</button>
                                        <button type="button" x-on:click="$dispatch('open-modal', 'reject-void-{{ $sale->id }}')" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Reject Void') }}</button>
                                    @endcan

                                    @can('create', [\App\Models\SaleReturn::class, $sale])
                                        <button type="button" wire:click="openReturnModal({{ $sale->id }})" x-on:click="$dispatch('open-modal', 'return-sale-{{ $sale->id }}')" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Return') }}</button>
                                    @endcan

                                    @foreach ($sale->returns->where('status', 'pending') as $pendingReturn)
                                        @can('resolve', $pendingReturn)
                                            <button type="button" x-on:click="$dispatch('open-modal', 'approve-return-{{ $pendingReturn->id }}')" class="text-green-600 dark:text-green-400 hover:text-green-900 dark:hover:text-green-300">{{ __('Approve Return') }}</button>
                                            <button type="button" x-on:click="$dispatch('open-modal', 'reject-return-{{ $pendingReturn->id }}')" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Cancel Return') }}</button>
                                        @endcan
                                    @endforeach
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No sales yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @foreach ($this->sales as $sale)
        <x-modal name="sale-view-{{ $sale->id }}" focusable>
            <div class="p-6 space-y-4 text-sm">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $sale->receipt_number }}</h2>
                    <x-sale-status-badge :sale="$sale" />
                </div>

                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-gray-500 dark:text-gray-400">
                    <div>{{ __('Branch') }}</div>
                    <div class="text-gray-900 dark:text-gray-100">{{ $sale->branch->name }}</div>
                    <div>{{ __('Cashier') }}</div>
                    <div class="text-gray-900 dark:text-gray-100">{{ $sale->cashier->name }}</div>
                    <div>{{ __('Date') }}</div>
                    <div class="text-gray-900 dark:text-gray-100">{{ $sale->created_at->format('d M Y, H:i') }}</div>
                    @if ($sale->customer)
                        <div>{{ __('Customer') }}</div>
                        <div class="text-gray-900 dark:text-gray-100">{{ $sale->customer->name }}</div>
                    @endif
                    @if ($sale->void_reason)
                        <div>{{ __('Void Reason') }}</div>
                        <div class="text-gray-900 dark:text-gray-100">{{ $sale->void_reason }}</div>
                    @endif
                </div>

                <div class="overflow-hidden border border-gray-200 dark:border-gray-700 rounded-md">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Item') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Qty') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Price') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($sale->items as $saleItem)
                                <tr>
                                    <td class="px-3 py-2 whitespace-nowrap text-gray-900 dark:text-gray-100">{{ $saleItem->item->sku }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $saleItem->quantity }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ number_format($saleItem->unit_price, 2) }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ number_format($saleItem->line_total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    <h3 class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">{{ __('Payments') }}</h3>
                    @foreach ($sale->payments as $payment)
                        <div class="flex justify-between text-gray-700 dark:text-gray-300">
                            <span>{{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}</span>
                            <span>{{ number_format($payment->amount, 2) }}</span>
                        </div>
                    @endforeach
                </div>

                @if ($sale->returns->isNotEmpty())
                    <div>
                        <h3 class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">{{ __('Returns') }}</h3>
                        @foreach ($sale->returns as $return)
                            <div class="border border-gray-200 dark:border-gray-700 rounded-md p-2 mb-2">
                                <div class="flex justify-between text-gray-700 dark:text-gray-300">
                                    <span>{{ $return->reason }}</span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $return->status === 'approved' ? 'bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400' : 'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400' }}">
                                        {{ ucfirst($return->status) }}
                                    </span>
                                </div>
                                @foreach ($return->items as $returnItem)
                                    <div class="text-gray-500 dark:text-gray-400 text-xs mt-1">
                                        {{ $returnItem->saleItem->item->sku }} &times; {{ $returnItem->quantity_returned }}
                                        {{ $returnItem->restock ? __('(restocked)') : __('(not restocked)') }}
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="border-t border-gray-200 dark:border-gray-700 pt-3 space-y-1">
                    @if ($sale->tax_total > 0)
                        <div class="flex justify-between text-gray-500 dark:text-gray-400">
                            <span>{{ __('Subtotal') }}</span>
                            <span>{{ number_format($sale->subtotal, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-gray-500 dark:text-gray-400">
                            <span>{{ __('Tax') }}</span>
                            <span>{{ number_format($sale->tax_total, 2) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between font-semibold text-gray-900 dark:text-gray-100">
                        <span>{{ __('Grand Total') }}</span>
                        <span>{{ number_format($sale->grand_total, 2) }}</span>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <a href="{{ route('sales.receipt', $sale) }}" target="_blank">
                        <x-secondary-button type="button">{{ __('Print Receipt') }}</x-secondary-button>
                    </a>
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </x-modal>

        @can('requestVoid', $sale)
            <x-modal name="void-sale-{{ $sale->id }}" focusable>
                <form wire:submit="requestVoid" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Request void for :receipt', ['receipt' => $sale->receipt_number]) }}</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('A manager must approve this before stock is restored.') }}</p>

                    <div class="mt-4">
                        <x-input-label for="void-reason-{{ $sale->id }}" :value="__('Reason')" />
                        <textarea wire:model="voidReason" id="void-reason-{{ $sale->id }}" rows="2" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full"></textarea>
                        <x-input-error :messages="$errors->get('voidReason')" class="mt-2" />
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                        <x-danger-button type="submit">{{ __('Submit Void Request') }}</x-danger-button>
                    </div>
                </form>
            </x-modal>
        @endcan

        @can('resolveVoid', $sale)
            <x-confirm-modal
                name="approve-void-{{ $sale->id }}"
                :title="__('Approve this void?')"
                confirm-action="resolveVoid({{ $sale->id }}, true)"
                :confirm-label="__('Approve')"
            >
                {{ __('Reason') }}: {{ $sale->void_reason }}. {{ __('This will restore the stock and mark the sale void.') }}
            </x-confirm-modal>

            <x-confirm-modal
                name="reject-void-{{ $sale->id }}"
                :title="__('Reject this void request?')"
                confirm-action="resolveVoid({{ $sale->id }}, false)"
                :confirm-label="__('Reject')"
            >
                {{ __('The sale will remain completed and no stock will be affected.') }}
            </x-confirm-modal>
        @endcan

        @can('create', [\App\Models\SaleReturn::class, $sale])
            <x-modal name="return-sale-{{ $sale->id }}" focusable max-width="lg">
                <form wire:submit="requestReturn" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Request return for :receipt', ['receipt' => $sale->receipt_number]) }}</h2>

                    <div class="mt-4">
                        <x-input-label for="return-reason-{{ $sale->id }}" :value="__('Reason')" />
                        <textarea wire:model="returnReason" id="return-reason-{{ $sale->id }}" rows="2" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full"></textarea>
                        <x-input-error :messages="$errors->get('returnReason')" class="mt-2" />
                    </div>

                    @if ($returningSaleId === $sale->id)
                        <div class="mt-4 space-y-3">
                            @foreach ($returnLines as $index => $line)
                                <div wire:key="return-line-{{ $sale->id }}-{{ $index }}" class="grid grid-cols-4 gap-3 items-end border-b border-gray-100 dark:border-gray-700 pb-3">
                                    <div class="col-span-2 text-sm text-gray-900 dark:text-gray-100">
                                        {{ $line['sku'] }}
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Sold') }} {{ $line['quantity_sold'] }} &middot; {{ __('Remaining') }} {{ $line['remaining'] }}</div>
                                    </div>
                                    <div>
                                        <x-input-label :value="__('Qty to Return')" />
                                        <x-text-input wire:model="returnLines.{{ $index }}.quantity_returned" class="block mt-1 w-full" type="number" step="0.001" min="0" />
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input wire:model="returnLines.{{ $index }}.restock" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Restock') }}</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('returnLines')" class="mt-2" />
                    @endif

                    <div class="mt-6 flex justify-end space-x-3">
                        <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                        <x-primary-button type="submit">{{ __('Submit Return Request') }}</x-primary-button>
                    </div>
                </form>
            </x-modal>
        @endcan

        @foreach ($sale->returns->where('status', 'pending') as $pendingReturn)
            @can('resolve', $pendingReturn)
                <x-confirm-modal
                    name="approve-return-{{ $pendingReturn->id }}"
                    :title="__('Approve this return?')"
                    confirm-action="resolveReturn({{ $pendingReturn->id }}, true)"
                    :confirm-label="__('Approve')"
                >
                    {{ __('Restockable lines will be added back to stock.') }}
                </x-confirm-modal>

                <x-confirm-modal
                    name="reject-return-{{ $pendingReturn->id }}"
                    :title="__('Cancel this return request?')"
                    confirm-action="resolveReturn({{ $pendingReturn->id }}, false)"
                    :confirm-label="__('Cancel Return')"
                >
                    {{ __('The request will be removed with no stock effect.') }}
                </x-confirm-modal>
            @endcan
        @endforeach
    @endforeach
</div>
