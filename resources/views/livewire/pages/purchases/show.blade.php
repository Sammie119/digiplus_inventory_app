<?php

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?Purchase $purchase = null;

    public ?int $branch_id = null;
    public ?int $supplier_id = null;
    public string $purchase_reference = '';
    public array $lines = [];

    public function mount(?Purchase $purchase = null): void
    {
        $actor = Auth::user();

        if ($purchase) {
            Gate::authorize('view', $purchase);

            $this->purchase = $purchase;
            $this->branch_id = $purchase->branch_id;
            $this->supplier_id = $purchase->supplier_id;
            $this->purchase_reference = (string) $purchase->purchase_reference;
            $this->lines = $purchase->items->map(fn ($line) => [
                'item_id' => $line->item_id,
                'quantity' => (string) $line->quantity,
                'unit_cost' => (string) $line->unit_cost,
                'batch_number' => $line->batch?->batch_number ?? '',
                'expiry_date' => $line->batch?->expiry_date?->toDateString(),
            ])->all();
        } else {
            Gate::authorize('create', Purchase::class);

            $this->branch_id = $actor->hasRole('Business Owner') ? null : $actor->branch_id;
            $this->addLine();
        }
    }

    #[Computed]
    public function isDraft(): bool
    {
        return ! $this->purchase || $this->purchase->status === 'draft';
    }

    #[Computed]
    public function branchOptions()
    {
        $actor = Auth::user();

        if ($actor->hasRole('Business Owner')) {
            return Branch::orderBy('name')->get();
        }

        return Branch::where('id', $actor->branch_id)->get();
    }

    #[Computed]
    public function supplierOptions()
    {
        return Supplier::orderBy('name')->get();
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
                'label' => $item->sku.' ('.$item->baseUnit->abbreviation.')',
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function linesTotal(): float
    {
        return collect($this->lines)->sum(
            fn ($line) => (float) ($line['quantity'] ?: 0) * (float) ($line['unit_cost'] ?: 0)
        );
    }

    public function itemTracksBatches(?int $itemId): bool
    {
        if (! $itemId) {
            return false;
        }

        return (bool) $this->itemOptions->firstWhere('id', $itemId)?->track_batches;
    }

    public function addLine(): void
    {
        $this->lines[] = ['item_id' => null, 'quantity' => '', 'unit_cost' => '', 'batch_number' => '', 'expiry_date' => null];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function saveDraft(): void
    {
        Gate::authorize($this->purchase ? 'update' : 'create', $this->purchase ?? Purchase::class);

        $tenantId = Auth::user()->tenant_id;

        $validated = $this->validate([
            'branch_id' => ['required', Rule::in($this->branchOptions->pluck('id')->all())],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'purchase_reference' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:100'],
            'lines.*.expiry_date' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($validated, $tenantId) {
            if ($this->purchase) {
                $this->purchase->update([
                    'branch_id' => $validated['branch_id'],
                    'supplier_id' => $validated['supplier_id'],
                    'purchase_reference' => $validated['purchase_reference'] ?: null,
                ]);
                $this->purchase->items()->delete();
            } else {
                $this->purchase = Purchase::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $validated['branch_id'],
                    'supplier_id' => $validated['supplier_id'],
                    'purchase_reference' => $validated['purchase_reference'] ?: null,
                    'status' => 'draft',
                ]);
            }

            $total = 0;

            foreach ($validated['lines'] as $line) {
                $batchId = null;

                if (filled($line['batch_number'] ?? null)) {
                    $batch = Batch::firstOrCreate(
                        ['item_id' => $line['item_id'], 'batch_number' => $line['batch_number']],
                        ['tenant_id' => $tenantId, 'expiry_date' => $line['expiry_date'] ?: null],
                    );
                    $batchId = $batch->id;
                }

                $this->purchase->items()->create([
                    'item_id' => $line['item_id'],
                    'batch_id' => $batchId,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                ]);

                $total += $line['quantity'] * $line['unit_cost'];
            }

            $this->purchase->update(['total_cost' => $total]);
        });

        $this->redirectRoute('purchases.show', $this->purchase, navigate: true);
    }

    public function receive(): void
    {
        Gate::authorize('receive', $this->purchase);

        $actor = Auth::user();

        DB::transaction(function () use ($actor) {
            foreach ($this->purchase->items as $line) {
                $existingQtyBefore = (float) StockLevel::where('item_id', $line->item_id)->sum('quantity_on_hand');

                StockMovement::create([
                    'tenant_id' => $actor->tenant_id,
                    'branch_id' => $this->purchase->branch_id,
                    'item_id' => $line->item_id,
                    'batch_id' => $line->batch_id,
                    'movement_type' => 'purchase',
                    'quantity' => $line->quantity,
                    'unit_cost' => $line->unit_cost,
                    'reference_type' => Purchase::class,
                    'reference_id' => $this->purchase->id,
                    'performed_by' => $actor->id,
                ]);

                $stockLevel = StockLevel::firstOrCreate(
                    [
                        'tenant_id' => $actor->tenant_id,
                        'branch_id' => $this->purchase->branch_id,
                        'item_id' => $line->item_id,
                        'batch_id' => $line->batch_id,
                    ],
                    ['quantity_on_hand' => 0],
                );
                $stockLevel->increment('quantity_on_hand', $line->quantity);

                $newQty = (float) $line->quantity;
                $newTotalQty = $existingQtyBefore + $newQty;

                if ($newTotalQty > 0) {
                    $item = $line->item;
                    $weightedCost = (($existingQtyBefore * (float) $item->cost_price) + ($newQty * (float) $line->unit_cost)) / $newTotalQty;
                    $item->update(['cost_price' => round($weightedCost, 2)]);
                }
            }

            $this->purchase->update([
                'status' => 'received',
                'received_by' => $actor->id,
            ]);
        });

        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-receive-purchase' }))");
        $this->dispatch('toast', message: __('Purchase received — stock updated.'), type: 'success');
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
            {{ $purchase ? ($purchase->purchase_reference ?? '#'.$purchase->id) : __('New Purchase') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <a href="{{ route('purchases.index') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                &larr; {{ __('Back to Purchases') }}
            </a>

            @if ($purchase && $purchase->status === 'received')
                <div class="p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 rounded-md text-sm">
                    {{ __('This purchase was received on :date and can no longer be edited.', ['date' => $purchase->updated_at->format('d M Y, H:i')]) }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-6">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <x-input-label for="purchase-branch" :value="__('Branch')" />
                        @if ($this->isDraft)
                            <select wire:model="branch_id" id="purchase-branch" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                                <option value="">{{ __('Select a branch') }}</option>
                                @foreach ($this->branchOptions as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('branch_id')" class="mt-2" />
                        @else
                            <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $purchase->branch->name }}</p>
                        @endif
                    </div>

                    <div>
                        <x-input-label for="purchase-supplier" :value="__('Supplier')" />
                        @if ($this->isDraft)
                            <select wire:model="supplier_id" id="purchase-supplier" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                                <option value="">{{ __('Select a supplier') }}</option>
                                @foreach ($this->supplierOptions as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('supplier_id')" class="mt-2" />
                        @else
                            <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $purchase->supplier->name }}</p>
                        @endif
                    </div>

                    <div>
                        <x-input-label for="purchase-reference" :value="__('Reference (optional)')" />
                        @if ($this->isDraft)
                            <x-text-input wire:model="purchase_reference" id="purchase-reference" class="block mt-1 w-full" type="text" />
                            <x-input-error :messages="$errors->get('purchase_reference')" class="mt-2" />
                        @else
                            <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $purchase->purchase_reference ?? '—' }}</p>
                        @endif
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Line Items') }}</h3>

                    @if ($this->isDraft)
                        <div class="space-y-4">
                            @foreach ($lines as $index => $line)
                                <div wire:key="line-{{ $index }}" class="border border-gray-200 dark:border-gray-700 rounded-md p-4">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Line') }} {{ $index + 1 }}</span>
                                        <div class="flex items-center gap-3">
                                            <span class="text-xs font-medium text-gray-600 dark:text-gray-300">
                                                {{ __('Item Total') }}: {{ number_format((float) ($line['quantity'] ?: 0) * (float) ($line['unit_cost'] ?: 0), 2) }}
                                            </span>
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

                                        <div>
                                            <x-input-label :value="__('Unit Cost')" />
                                            <x-text-input wire:model.live="lines.{{ $index }}.unit_cost" class="block mt-1 w-full" type="number" step="0.01" min="0" />
                                            <x-input-error :messages="$errors->get('lines.'.$index.'.unit_cost')" class="mt-2" />
                                        </div>

                                        @if ($this->itemTracksBatches($line['item_id']))
                                            <div>
                                                <x-input-label :value="__('Batch Number (optional)')" />
                                                <x-text-input wire:model="lines.{{ $index }}.batch_number" class="block mt-1 w-full" type="text" />
                                            </div>

                                            <div>
                                                <x-input-label :value="__('Expiry Date (optional)')" />
                                                <x-text-input wire:model="lines.{{ $index }}.expiry_date" class="block mt-1 w-full" type="date" />
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
                    @else
                        <div class="overflow-hidden border border-gray-200 dark:border-gray-700 rounded-md">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-900/40">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Item') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Batch') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Quantity') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Unit Cost') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Item Total') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach ($purchase->items as $line)
                                        <tr>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $line->item->sku }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $line->batch?->batch_number ?? '—' }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $line->quantity }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($line->unit_cost, 2) }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($line->quantity * $line->unit_cost, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-between border-t border-gray-200 dark:border-gray-700 pt-4">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Total') }}: <span class="text-gray-900 dark:text-gray-100 font-medium">{{ number_format($this->isDraft ? $this->linesTotal : $purchase->total_cost, 2) }}</span>
                    </div>

                    @if ($this->isDraft)
                        <div class="flex gap-3">
                            <x-primary-button wire:click="saveDraft">
                                {{ __('Save Draft') }}
                            </x-primary-button>

                            @if ($purchase)
                                <x-danger-button type="button" x-on:click="$dispatch('open-modal', 'confirm-receive-purchase')">
                                    {{ __('Mark as Received') }}
                                </x-danger-button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($purchase)
        <x-confirm-modal
            name="confirm-receive-purchase"
            :title="__('Mark this purchase as received?')"
            confirm-action="receive"
            :confirm-label="__('Receive')"
        >
            {{ __('This will add the stock to the branch, update item costs, and lock the purchase from further edits.') }}
        </x-confirm-modal>
    @endif
</div>
