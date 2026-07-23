<?php

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Item;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTransfer;
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
    public ?StockTransfer $stockTransfer = null;

    public ?int $from_branch_id = null;
    public ?int $to_branch_id = null;
    public array $lines = [];

    public function mount(?StockTransfer $stockTransfer = null): void
    {
        $actor = Auth::user();

        if ($stockTransfer) {
            Gate::authorize('view', $stockTransfer);

            $this->stockTransfer = $stockTransfer;
            $this->from_branch_id = $stockTransfer->from_branch_id;
            $this->to_branch_id = $stockTransfer->to_branch_id;
            $this->lines = $stockTransfer->items->map(fn ($line) => [
                'item_id' => $line->item_id,
                'batch_id' => $line->batch_id,
                'quantity_sent' => (string) $line->quantity_sent,
                'quantity_received' => $line->quantity_received !== null ? (string) $line->quantity_received : (string) $line->quantity_sent,
            ])->all();
        } else {
            Gate::authorize('create', StockTransfer::class);

            $this->from_branch_id = $actor->hasRole('Business Owner') ? null : $actor->branch_id;
            $this->addLine();
        }
    }

    #[Computed]
    public function isPending(): bool
    {
        return ! $this->stockTransfer || $this->stockTransfer->status === 'pending';
    }

    /**
     * Whether the current actor may edit this transfer's fields right now —
     * not just whether it's pending. A pending transfer initiated by another
     * branch is still visible (stock.view) but must render read-only, since
     * only the source branch (or Business Owner) can update/ship/cancel it.
     */
    #[Computed]
    public function canEdit(): bool
    {
        if (! $this->stockTransfer) {
            return true;
        }

        return $this->stockTransfer->status === 'pending' && Gate::allows('update', $this->stockTransfer);
    }

    #[Computed]
    public function isInTransit(): bool
    {
        return $this->stockTransfer && $this->stockTransfer->status === 'in_transit';
    }

    #[Computed]
    public function fromBranchOptions()
    {
        $actor = Auth::user();

        if ($actor->hasRole('Business Owner')) {
            return Branch::orderBy('name')->get();
        }

        return Branch::where('id', $actor->branch_id)->get();
    }

    #[Computed]
    public function toBranchOptions()
    {
        return Branch::where('id', '!=', $this->from_branch_id ?? 0)->orderBy('name')->get();
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

        return Batch::where('item_id', $itemId)->orderBy('batch_number')->get();
    }

    public function addLine(): void
    {
        $this->lines[] = ['item_id' => null, 'batch_id' => null, 'quantity_sent' => '', 'quantity_received' => ''];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function saveDraft(): void
    {
        Gate::authorize($this->stockTransfer ? 'update' : 'create', $this->stockTransfer ?? StockTransfer::class);

        $tenantId = Auth::user()->tenant_id;

        $validated = $this->validate([
            'from_branch_id' => ['required', Rule::in($this->fromBranchOptions->pluck('id')->all())],
            'to_branch_id' => ['required', Rule::in($this->toBranchOptions->pluck('id')->all())],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.quantity_sent' => ['required', 'numeric', 'min:0.001'],
            'lines.*.batch_id' => ['nullable', 'exists:batches,id'],
        ]);

        DB::transaction(function () use ($validated, $tenantId) {
            if ($this->stockTransfer) {
                $this->stockTransfer->update([
                    'from_branch_id' => $validated['from_branch_id'],
                    'to_branch_id' => $validated['to_branch_id'],
                ]);
                $this->stockTransfer->items()->delete();
            } else {
                $this->stockTransfer = StockTransfer::create([
                    'tenant_id' => $tenantId,
                    'from_branch_id' => $validated['from_branch_id'],
                    'to_branch_id' => $validated['to_branch_id'],
                    'status' => 'pending',
                    'initiated_by' => Auth::id(),
                ]);
            }

            foreach ($validated['lines'] as $line) {
                $this->stockTransfer->items()->create([
                    'item_id' => $line['item_id'],
                    'batch_id' => $line['batch_id'] ?: null,
                    'quantity_sent' => $line['quantity_sent'],
                ]);
            }
        });

        $this->redirectRoute('stock-transfers.show', $this->stockTransfer, navigate: true);
    }

    public function ship(): void
    {
        Gate::authorize('ship', $this->stockTransfer);

        $actor = Auth::user();

        foreach ($this->stockTransfer->items as $line) {
            $onHand = (float) StockLevel::where('branch_id', $this->stockTransfer->from_branch_id)
                ->where('item_id', $line->item_id)
                ->where('batch_id', $line->batch_id)
                ->sum('quantity_on_hand');

            if ($onHand < (float) $line->quantity_sent) {
                $message = __('Insufficient stock at the source branch for :sku (:qty currently on hand).', ['sku' => $line->item->sku, 'qty' => $onHand]);
                $this->dispatch('toast', message: $message, type: 'error');

                throw ValidationException::withMessages(['ship' => $message]);
            }
        }

        DB::transaction(function () use ($actor) {
            foreach ($this->stockTransfer->items as $line) {
                StockMovement::create([
                    'tenant_id' => $actor->tenant_id,
                    'branch_id' => $this->stockTransfer->from_branch_id,
                    'item_id' => $line->item_id,
                    'batch_id' => $line->batch_id,
                    'movement_type' => 'transfer_out',
                    'quantity' => -$line->quantity_sent,
                    'unit_cost' => $line->item->cost_price,
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $this->stockTransfer->id,
                    'performed_by' => $actor->id,
                ]);

                $sourceLevel = StockLevel::firstOrCreate(
                    [
                        'tenant_id' => $actor->tenant_id,
                        'branch_id' => $this->stockTransfer->from_branch_id,
                        'item_id' => $line->item_id,
                        'batch_id' => $line->batch_id,
                    ],
                    ['quantity_on_hand' => 0],
                );
                $sourceLevel->decrement('quantity_on_hand', $line->quantity_sent);

                $destinationLevel = StockLevel::firstOrCreate(
                    [
                        'tenant_id' => $actor->tenant_id,
                        'branch_id' => $this->stockTransfer->to_branch_id,
                        'item_id' => $line->item_id,
                        'batch_id' => $line->batch_id,
                    ],
                    ['quantity_on_hand' => 0],
                );
                $destinationLevel->increment('quantity_in_transit', $line->quantity_sent);
            }

            $this->stockTransfer->update(['status' => 'in_transit']);
        });

        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-ship-transfer' }))");
        $this->dispatch('toast', message: __('Transfer shipped.'), type: 'success');
    }

    public function receive(): void
    {
        Gate::authorize('receive', $this->stockTransfer);

        $actor = Auth::user();

        $validated = $this->validate([
            'lines.*.quantity_received' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($actor, $validated) {
            foreach ($this->stockTransfer->items as $index => $line) {
                $quantityReceived = (float) $validated['lines'][$index]['quantity_received'];

                StockMovement::create([
                    'tenant_id' => $actor->tenant_id,
                    'branch_id' => $this->stockTransfer->to_branch_id,
                    'item_id' => $line->item_id,
                    'batch_id' => $line->batch_id,
                    'movement_type' => 'transfer_in',
                    'quantity' => $quantityReceived,
                    'unit_cost' => $line->item->cost_price,
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $this->stockTransfer->id,
                    'performed_by' => $actor->id,
                ]);

                $destinationLevel = StockLevel::firstOrCreate(
                    [
                        'tenant_id' => $actor->tenant_id,
                        'branch_id' => $this->stockTransfer->to_branch_id,
                        'item_id' => $line->item_id,
                        'batch_id' => $line->batch_id,
                    ],
                    ['quantity_on_hand' => 0],
                );
                $destinationLevel->decrement('quantity_in_transit', $line->quantity_sent);
                $destinationLevel->increment('quantity_on_hand', $quantityReceived);

                $line->update(['quantity_received' => $quantityReceived]);
            }

            $this->stockTransfer->update([
                'status' => 'received',
                'received_by' => $actor->id,
                'received_at' => now(),
            ]);
        });

        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-receive-transfer' }))");
        $this->dispatch('toast', message: __('Transfer received.'), type: 'success');
    }

    public function cancel(): void
    {
        Gate::authorize('cancel', $this->stockTransfer);

        $this->stockTransfer->update(['status' => 'cancelled']);

        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-cancel-transfer' }))");
        $this->dispatch('toast', message: __('Transfer cancelled.'), type: 'success');
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
            {{ $stockTransfer ? __('Transfer').' #'.$stockTransfer->id : __('New Stock Transfer') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <a href="{{ route('stock-transfers.index') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                &larr; {{ __('Back to Stock Transfers') }}
            </a>

            @if ($stockTransfer && $stockTransfer->status === 'in_transit')
                <div class="p-4 bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-400 rounded-md text-sm">
                    {{ __('This transfer has shipped and is awaiting receipt at :branch.', ['branch' => $stockTransfer->toBranch->name]) }}
                </div>
            @elseif ($stockTransfer && $stockTransfer->status === 'received')
                <div class="p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 rounded-md text-sm">
                    {{ __('This transfer was received on :date and can no longer be edited.', ['date' => $stockTransfer->received_at->format('d M Y, H:i')]) }}
                </div>
            @elseif ($stockTransfer && $stockTransfer->status === 'cancelled')
                <div class="p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 rounded-md text-sm">
                    {{ __('This transfer was cancelled.') }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="transfer-from-branch" :value="__('From Branch')" />
                        @if ($this->canEdit)
                            <select wire:model.live="from_branch_id" id="transfer-from-branch" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                                <option value="">{{ __('Select a branch') }}</option>
                                @foreach ($this->fromBranchOptions as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('from_branch_id')" class="mt-2" />
                        @else
                            <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $stockTransfer->fromBranch->name }}</p>
                        @endif
                    </div>

                    <div>
                        <x-input-label for="transfer-to-branch" :value="__('To Branch')" />
                        @if ($this->canEdit)
                            <select wire:model="to_branch_id" id="transfer-to-branch" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                                <option value="">{{ __('Select a branch') }}</option>
                                @foreach ($this->toBranchOptions as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('to_branch_id')" class="mt-2" />
                        @else
                            <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $stockTransfer->toBranch->name }}</p>
                        @endif
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Line Items') }}</h3>

                    @if ($this->canEdit)
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
                                            <x-text-input wire:model="lines.{{ $index }}.quantity_sent" class="block mt-1 w-full" type="number" step="0.001" min="0" />
                                            <x-input-error :messages="$errors->get('lines.'.$index.'.quantity_sent')" class="mt-2" />
                                        </div>

                                        @if ($this->itemTracksBatches($line['item_id']))
                                            <div>
                                                <x-input-label :value="__('Batch (optional)')" />
                                                <select wire:model="lines.{{ $index }}.batch_id" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                                                    <option value="">{{ __('No specific batch') }}</option>
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
                    @else
                        <div class="overflow-hidden border border-gray-200 dark:border-gray-700 rounded-md">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-900/40">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Item') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Batch') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Sent') }}</th>
                                        @if ($stockTransfer->status === 'received')
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Received') }}</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach ($stockTransfer->items as $line)
                                        <tr>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $line->item->sku }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $line->batch?->batch_number ?? '—' }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $line->quantity_sent }}</td>
                                            @if ($stockTransfer->status === 'received')
                                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $line->quantity_received }}</td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-end border-t border-gray-200 dark:border-gray-700 pt-4 gap-3">
                    @if ($this->canEdit)
                        <x-primary-button wire:click="saveDraft">
                            {{ __('Save Draft') }}
                        </x-primary-button>

                        @if ($stockTransfer)
                            @can('cancel', $stockTransfer)
                                <x-secondary-button type="button" x-on:click="$dispatch('open-modal', 'confirm-cancel-transfer')">
                                    {{ __('Cancel Transfer') }}
                                </x-secondary-button>
                            @endcan
                            @can('ship', $stockTransfer)
                                <x-danger-button type="button" x-on:click="$dispatch('open-modal', 'confirm-ship-transfer')">
                                    {{ __('Ship') }}
                                </x-danger-button>
                            @endcan
                        @endif
                    @elseif ($this->isInTransit)
                        @can('receive', $stockTransfer)
                            <x-primary-button type="button" x-on:click="$dispatch('open-modal', 'confirm-receive-transfer')">
                                {{ __('Receive Shipment') }}
                            </x-primary-button>
                        @endcan
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($stockTransfer)
        @can('ship', $stockTransfer)
            <x-modal name="confirm-ship-transfer" focusable>
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Ship this transfer?') }}</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('This will deduct stock from the source branch and mark it as in transit.') }}</p>
                    <x-input-error :messages="$errors->get('ship')" class="mt-2" />
                    <div class="mt-6 flex justify-end space-x-3">
                        <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                        <x-danger-button wire:click="ship">{{ __('Ship') }}</x-danger-button>
                    </div>
                </div>
            </x-modal>
        @endcan

        @can('cancel', $stockTransfer)
            <x-confirm-modal
                name="confirm-cancel-transfer"
                :title="__('Cancel this transfer?')"
                confirm-action="cancel"
                :confirm-label="__('Cancel Transfer')"
            >
                {{ __('Nothing has shipped yet, so no stock will be affected.') }}
            </x-confirm-modal>
        @endcan

        @can('receive', $stockTransfer)
            <x-modal name="confirm-receive-transfer" focusable>
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Confirm quantities received') }}</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Adjust any line where the quantity received differs from what was sent.') }}</p>

                    <div class="mt-4 space-y-3">
                        @foreach ($stockTransfer->items as $index => $line)
                            <div class="grid grid-cols-3 gap-3 items-center">
                                <div class="col-span-2 text-sm text-gray-900 dark:text-gray-100">
                                    {{ $line->item->sku }}
                                    <span class="text-gray-500 dark:text-gray-400">({{ __('sent') }} {{ $line->quantity_sent }})</span>
                                </div>
                                <div>
                                    <x-text-input wire:model="lines.{{ $index }}.quantity_received" class="block w-full" type="number" step="0.001" min="0" />
                                    <x-input-error :messages="$errors->get('lines.'.$index.'.quantity_received')" class="mt-1" />
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                        <x-primary-button wire:click="receive">{{ __('Confirm Receipt') }}</x-primary-button>
                    </div>
                </div>
            </x-modal>
        @endcan
    @endif
</div>
