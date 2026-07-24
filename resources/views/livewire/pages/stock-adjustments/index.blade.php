<?php

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Item;
use App\Models\StockAdjustment;
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
    public const REASON_CODES = ['damage', 'write_off', 'return_to_supplier', 'count_correction', 'other'];

    public ?int $branch_id = null;
    public ?int $item_id = null;
    public ?int $batch_id = null;
    public string $quantity = '';
    public string $direction = 'decrease';
    public string $reason_code = '';
    public string $notes = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', StockAdjustment::class);
    }

    #[Computed]
    public function adjustments()
    {
        $actor = Auth::user();

        $query = StockAdjustment::with(['item', 'branch', 'batch', 'requestedBy', 'approvedBy'])->latest();

        if (! $actor->hasRole('Business Owner')) {
            $query->where('branch_id', $actor->branch_id);
        }

        return $query->get();
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
                'code' => $item->barcode,
            ])
            ->values()
            ->all();
    }

    public function itemTracksBatches(): bool
    {
        if (! $this->item_id) {
            return false;
        }

        return (bool) $this->itemOptions->firstWhere('id', $this->item_id)?->track_batches;
    }

    #[Computed]
    public function batchOptions()
    {
        if (! $this->item_id || ! $this->itemTracksBatches()) {
            return collect();
        }

        return Batch::where('item_id', $this->item_id)->orderBy('batch_number')->get();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', StockAdjustment::class);

        $actor = Auth::user();

        $this->reset(['item_id', 'batch_id', 'quantity', 'reason_code', 'notes']);
        $this->direction = 'decrease';
        $this->branch_id = $actor->hasRole('Business Owner') ? null : $actor->branch_id;
        $this->resetErrorBag();
        unset($this->batchOptions);
    }

    public function createAdjustment(): void
    {
        Gate::authorize('create', StockAdjustment::class);

        $tenantId = Auth::user()->tenant_id;

        $validated = $this->validate([
            'branch_id' => ['required', Rule::in($this->branchOptions->pluck('id')->all())],
            'item_id' => ['required', 'exists:items,id'],
            'batch_id' => ['nullable', Rule::in($this->batchOptions->pluck('id')->all())],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'direction' => ['required', Rule::in(['increase', 'decrease'])],
            'reason_code' => ['required', Rule::in(self::REASON_CODES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        StockAdjustment::create([
            'tenant_id' => $tenantId,
            'branch_id' => $validated['branch_id'],
            'item_id' => $validated['item_id'],
            'batch_id' => $validated['batch_id'] ?: null,
            'quantity_delta' => $validated['direction'] === 'decrease' ? -$validated['quantity'] : $validated['quantity'],
            'reason_code' => $validated['reason_code'],
            'notes' => $validated['notes'] ?: null,
            'status' => 'pending',
            'requested_by' => Auth::id(),
        ]);

        unset($this->adjustments);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'adjustment-form' }))");
        $this->dispatch('toast', message: __('Adjustment submitted for approval.'), type: 'success');
    }

    public function approve(int $id): void
    {
        $adjustment = StockAdjustment::findOrFail($id);
        Gate::authorize('approve', $adjustment);

        $actor = Auth::user();
        $delta = (float) $adjustment->quantity_delta;

        if ($delta < 0) {
            $currentQty = (float) StockLevel::where('branch_id', $adjustment->branch_id)
                ->where('item_id', $adjustment->item_id)
                ->where('batch_id', $adjustment->batch_id)
                ->sum('quantity_on_hand');

            if ($currentQty + $delta < 0) {
                $message = __('Insufficient stock to apply this adjustment (:qty currently on hand).', ['qty' => $currentQty]);
                $this->dispatch('toast', message: $message, type: 'error');

                throw ValidationException::withMessages(['approve' => $message]);
            }
        }

        DB::transaction(function () use ($adjustment, $actor, $delta) {
            StockMovement::create([
                'tenant_id' => $actor->tenant_id,
                'branch_id' => $adjustment->branch_id,
                'item_id' => $adjustment->item_id,
                'batch_id' => $adjustment->batch_id,
                'movement_type' => 'adjustment',
                'quantity' => $delta,
                'unit_cost' => $adjustment->item->cost_price,
                'reference_type' => StockAdjustment::class,
                'reference_id' => $adjustment->id,
                'reason_code' => $adjustment->reason_code,
                'performed_by' => $actor->id,
            ]);

            $stockLevel = StockLevel::firstOrCreate(
                [
                    'tenant_id' => $actor->tenant_id,
                    'branch_id' => $adjustment->branch_id,
                    'item_id' => $adjustment->item_id,
                    'batch_id' => $adjustment->batch_id,
                ],
                ['quantity_on_hand' => 0],
            );
            $stockLevel->increment('quantity_on_hand', $delta);

            $adjustment->update(['status' => 'approved', 'approved_by' => $actor->id]);
        });

        unset($this->adjustments);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'approve-adjustment-{$id}' }))");
        $this->dispatch('toast', message: __('Adjustment approved.'), type: 'success');
    }

    public function reject(int $id): void
    {
        $adjustment = StockAdjustment::findOrFail($id);
        Gate::authorize('reject', $adjustment);

        $adjustment->update(['status' => 'rejected', 'approved_by' => Auth::id()]);

        unset($this->adjustments);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'reject-adjustment-{$id}' }))");
        $this->dispatch('toast', message: __('Adjustment rejected.'), type: 'success');
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Stock Adjustments') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @can('create', \App\Models\StockAdjustment::class)
                <div class="flex justify-end">
                    <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'adjustment-form')">
                        <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                        {{ __('New Adjustment') }}
                    </x-primary-button>
                </div>
            @endcan

            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->adjustments as $adjustment)
                    <div wire:key="adjustment-card-{{ $adjustment->id }}" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $adjustment->item->sku }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ $adjustment->branch->name }} &middot; {{ str($adjustment->reason_code)->headline() }}</div>
                            </div>
                            <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                @if ($adjustment->status === 'approved') bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400
                                @elseif ($adjustment->status === 'rejected') bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400
                                @else bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400
                                @endif
                            ">
                                {{ ucfirst($adjustment->status) }}
                            </span>
                        </div>
                        <div class="mt-3 text-sm font-medium {{ $adjustment->quantity_delta >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $adjustment->quantity_delta >= 0 ? '+' : '' }}{{ $adjustment->quantity_delta }}
                        </div>
                        <div class="mt-3 flex items-center gap-2">
                            <button type="button" wire:click="$dispatch('open-modal', 'adjustment-view-{{ $adjustment->id }}')" class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View') }}</button>
                            @can('approve', $adjustment)
                                <button type="button" x-on:click="$dispatch('open-modal', 'approve-adjustment-{{ $adjustment->id }}')" class="text-xs text-green-600 dark:text-green-400 hover:text-green-900 dark:hover:text-green-300">{{ __('Approve') }}</button>
                                <button type="button" x-on:click="$dispatch('open-modal', 'reject-adjustment-{{ $adjustment->id }}')" class="text-xs text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Reject') }}</button>
                            @endcan
                        </div>
                    </div>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No stock adjustments yet.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Item') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Branch') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Quantity') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Reason') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Requested By') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->adjustments as $adjustment)
                            <tr wire:key="adjustment-{{ $adjustment->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $adjustment->item->sku }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $adjustment->branch->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium {{ $adjustment->quantity_delta >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ $adjustment->quantity_delta >= 0 ? '+' : '' }}{{ $adjustment->quantity_delta }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ str($adjustment->reason_code)->headline() }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                        @if ($adjustment->status === 'approved') bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400
                                        @elseif ($adjustment->status === 'rejected') bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400
                                        @else bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400
                                        @endif
                                    ">
                                        {{ ucfirst($adjustment->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $adjustment->requestedBy->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-3">
                                    <button type="button" x-on:click="$dispatch('open-modal', 'adjustment-view-{{ $adjustment->id }}')" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View') }}</button>
                                    @can('approve', $adjustment)
                                        <button type="button" x-on:click="$dispatch('open-modal', 'approve-adjustment-{{ $adjustment->id }}')" class="text-green-600 dark:text-green-400 hover:text-green-900 dark:hover:text-green-300">{{ __('Approve') }}</button>
                                        <button type="button" x-on:click="$dispatch('open-modal', 'reject-adjustment-{{ $adjustment->id }}')" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Reject') }}</button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No stock adjustments yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @can('create', \App\Models\StockAdjustment::class)
        <x-modal name="adjustment-form" focusable>
            <form wire:submit="createAdjustment" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('New Stock Adjustment') }}</h2>

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="adjustment-branch" :value="__('Branch')" />
                        <select wire:model="branch_id" id="adjustment-branch" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                            <option value="">{{ __('Select a branch') }}</option>
                            @foreach ($this->branchOptions as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('branch_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label :value="__('Item')" />
                        <x-searchable-select
                            :options="$this->itemSelectOptions"
                            wire-model="item_id"
                            :placeholder="__('Search items…')"
                        />
                        <x-input-error :messages="$errors->get('item_id')" class="mt-2" />
                    </div>

                    @if ($this->itemTracksBatches())
                        <div>
                            <x-input-label for="adjustment-batch" :value="__('Batch (optional)')" />
                            <select wire:model="batch_id" id="adjustment-batch" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                                <option value="">{{ __('No specific batch') }}</option>
                                @foreach ($this->batchOptions as $batch)
                                    <option value="{{ $batch->id }}">{{ $batch->batch_number }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('batch_id')" class="mt-2" />
                        </div>
                    @endif

                    <div>
                        <x-input-label for="adjustment-direction" :value="__('Direction')" />
                        <select wire:model="direction" id="adjustment-direction" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                            <option value="decrease">{{ __('Decrease stock') }}</option>
                            <option value="increase">{{ __('Increase stock') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('direction')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="adjustment-quantity" :value="__('Quantity')" />
                        <x-text-input wire:model="quantity" id="adjustment-quantity" class="block mt-1 w-full" type="number" step="0.001" min="0" />
                        <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="adjustment-reason" :value="__('Reason')" />
                        <select wire:model="reason_code" id="adjustment-reason" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                            <option value="">{{ __('Select a reason') }}</option>
                            @foreach (self::REASON_CODES as $code)
                                <option value="{{ $code }}">{{ str($code)->headline() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('reason_code')" class="mt-2" />
                    </div>

                    <div class="sm:col-span-2">
                        <x-input-label for="adjustment-notes" :value="__('Notes (optional)')" />
                        <textarea wire:model="notes" id="adjustment-notes" rows="2" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full"></textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="submit">{{ __('Submit Request') }}</x-primary-button>
                </div>
            </form>
        </x-modal>
    @endcan

    @foreach ($this->adjustments as $adjustment)
        <x-modal name="adjustment-view-{{ $adjustment->id }}" focusable max-width="md">
            <div class="p-6 space-y-3 text-sm">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Adjustment Details') }}</h2>

                <div class="grid grid-cols-2 gap-x-4 gap-y-2">
                    <div class="text-gray-500 dark:text-gray-400">{{ __('Item') }}</div>
                    <div class="text-gray-900 dark:text-gray-100">{{ $adjustment->item->sku }}</div>

                    <div class="text-gray-500 dark:text-gray-400">{{ __('Branch') }}</div>
                    <div class="text-gray-900 dark:text-gray-100">{{ $adjustment->branch->name }}</div>

                    <div class="text-gray-500 dark:text-gray-400">{{ __('Batch') }}</div>
                    <div class="text-gray-900 dark:text-gray-100">{{ $adjustment->batch?->batch_number ?? '—' }}</div>

                    <div class="text-gray-500 dark:text-gray-400">{{ __('Quantity') }}</div>
                    <div class="{{ $adjustment->quantity_delta >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $adjustment->quantity_delta >= 0 ? '+' : '' }}{{ $adjustment->quantity_delta }}
                    </div>

                    <div class="text-gray-500 dark:text-gray-400">{{ __('Reason') }}</div>
                    <div class="text-gray-900 dark:text-gray-100">{{ str($adjustment->reason_code)->headline() }}</div>

                    <div class="text-gray-500 dark:text-gray-400">{{ __('Status') }}</div>
                    <div class="text-gray-900 dark:text-gray-100">{{ ucfirst($adjustment->status) }}</div>

                    <div class="text-gray-500 dark:text-gray-400">{{ __('Requested By') }}</div>
                    <div class="text-gray-900 dark:text-gray-100">{{ $adjustment->requestedBy->name }} &middot; {{ $adjustment->created_at->format('d M Y, H:i') }}</div>

                    @if ($adjustment->approvedBy)
                        <div class="text-gray-500 dark:text-gray-400">{{ __('Resolved By') }}</div>
                        <div class="text-gray-900 dark:text-gray-100">{{ $adjustment->approvedBy->name }} &middot; {{ $adjustment->updated_at->format('d M Y, H:i') }}</div>
                    @endif

                    @if ($adjustment->notes)
                        <div class="text-gray-500 dark:text-gray-400">{{ __('Notes') }}</div>
                        <div class="text-gray-900 dark:text-gray-100">{{ $adjustment->notes }}</div>
                    @endif
                </div>

                <div class="mt-6 flex justify-end">
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </x-modal>

        @can('approve', $adjustment)
            <x-modal name="approve-adjustment-{{ $adjustment->id }}" focusable>
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Approve this adjustment?') }}</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('This will apply the quantity change to the branch\'s stock level immediately.') }}</p>
                    <x-input-error :messages="$errors->get('approve')" class="mt-2" />
                    <div class="mt-6 flex justify-end space-x-3">
                        <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                        <x-danger-button wire:click="approve({{ $adjustment->id }})">{{ __('Approve') }}</x-danger-button>
                    </div>
                </div>
            </x-modal>

            <x-confirm-modal
                name="reject-adjustment-{{ $adjustment->id }}"
                :title="__('Reject this adjustment?')"
                confirm-action="reject({{ $adjustment->id }})"
                :confirm-label="__('Reject')"
            >
                {{ __('This request will be marked rejected and stock will not be affected.') }}
            </x-confirm-modal>
        @endcan
    @endforeach
</div>
