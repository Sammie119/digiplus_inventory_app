<?php

use App\Models\Batch;
use App\Models\Branch;
use App\Models\FinancialYear;
use App\Models\Item;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\Tenant;
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
    public ?StockTake $stockTake = null;

    public ?int $branch_id = null;
    public string $notes = '';
    public array $lines = [];

    public function mount(?StockTake $stockTake = null): void
    {
        $actor = Auth::user();

        if ($stockTake) {
            Gate::authorize('view', $stockTake);

            $this->stockTake = $stockTake;
            $this->lines = $stockTake->items()->with(['item', 'batch'])->orderBy('id')->get()
                ->map(fn ($line) => [
                    'id' => $line->id,
                    'counted_quantity' => $line->counted_quantity === null ? '' : (string) $line->counted_quantity,
                    'notes' => (string) $line->notes,
                ])
                ->all();

            return;
        }

        Gate::authorize('create', StockTake::class);

        $this->branch_id = $actor->hasRole('Business Owner') ? null : $actor->branch_id;
    }

    #[Computed]
    public function openFinancialYear(): ?FinancialYear
    {
        return FinancialYear::where('tenant_id', Auth::user()->tenant_id)->where('status', 'open')->first();
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

    public function start(): void
    {
        Gate::authorize('create', StockTake::class);

        $tenantId = Auth::user()->tenant_id;
        $financialYear = $this->openFinancialYear;

        if (! $financialYear) {
            $this->addError('branch_id', __('No financial year is open. Ask your Business Owner to open one first.'));

            return;
        }

        $validated = $this->validate([
            'branch_id' => ['required', Rule::in($this->branchOptions->pluck('id')->all())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $stockTake = DB::transaction(function () use ($validated, $tenantId, $financialYear) {
            Tenant::where('id', $tenantId)->lockForUpdate()->first();
            $sequence = StockTake::where('tenant_id', $tenantId)->count() + 1;

            $stockTake = StockTake::create([
                'tenant_id' => $tenantId,
                'reference' => sprintf('ST-%06d', $sequence),
                'branch_id' => $validated['branch_id'],
                'financial_year_id' => $financialYear->id,
                'status' => 'in_progress',
                'notes' => $validated['notes'] ?: null,
                'created_by' => Auth::id(),
                'started_at' => now(),
            ]);

            $items = Item::where('status', 'active')->get();

            foreach ($items as $item) {
                if ($item->track_batches) {
                    $batches = Batch::where('item_id', $item->id)->get();

                    foreach ($batches as $batch) {
                        $stockTake->items()->create([
                            'tenant_id' => $tenantId,
                            'item_id' => $item->id,
                            'batch_id' => $batch->id,
                            'system_quantity' => $this->stockLevelFor($validated['branch_id'], $item->id, $batch->id),
                        ]);
                    }

                    continue;
                }

                $stockTake->items()->create([
                    'tenant_id' => $tenantId,
                    'item_id' => $item->id,
                    'batch_id' => null,
                    'system_quantity' => $this->stockLevelFor($validated['branch_id'], $item->id, null),
                ]);
            }

            return $stockTake;
        });

        $this->redirectRoute('stock-takings.show', $stockTake, navigate: true);
    }

    private function stockLevelFor(int $branchId, int $itemId, ?int $batchId): float
    {
        return (float) (StockLevel::where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->where('batch_id', $batchId)
            ->value('quantity_on_hand') ?? 0);
    }

    private function persistLines(): void
    {
        $validated = $this->validate([
            'lines.*.id' => ['required', 'integer'],
            'lines.*.counted_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach ($validated['lines'] as $line) {
            $stockTakeItem = $this->stockTake->items()->find($line['id']);

            if (! $stockTakeItem) {
                continue;
            }

            $countedQuantity = $line['counted_quantity'] === '' ? null : $line['counted_quantity'];

            $stockTakeItem->update([
                'counted_quantity' => $countedQuantity,
                'variance' => $countedQuantity === null ? null : $countedQuantity - $stockTakeItem->system_quantity,
                'notes' => $line['notes'] ?: null,
            ]);
        }
    }

    public function saveProgress(): void
    {
        Gate::authorize('update', $this->stockTake);

        if ($this->stockTake->status !== 'in_progress') {
            $this->dispatch('toast', message: __('This stock take is no longer in progress.'), type: 'error');

            return;
        }

        $this->persistLines();

        $this->dispatch('toast', message: __('Progress saved.'), type: 'success');
    }

    public function complete(): void
    {
        Gate::authorize('update', $this->stockTake);

        if ($this->stockTake->status !== 'in_progress') {
            $this->dispatch('toast', message: __('This stock take is no longer in progress.'), type: 'error');

            return;
        }

        $this->persistLines();

        if ($this->stockTake->items()->whereNull('counted_quantity')->exists()) {
            $this->dispatch('toast', message: __('Every item must have a counted quantity before this stock take can be completed.'), type: 'error');

            return;
        }

        $this->stockTake->update(['status' => 'pending_approval', 'completed_at' => now()]);

        $this->dispatch('toast', message: __('Stock take completed and sent for approval.'), type: 'success');
    }

    public function cancel(): void
    {
        Gate::authorize('update', $this->stockTake);

        if (! in_array($this->stockTake->status, ['draft', 'in_progress'], true)) {
            $this->dispatch('toast', message: __('This stock take can no longer be cancelled.'), type: 'error');

            return;
        }

        $this->stockTake->update(['status' => 'cancelled']);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-cancel-stock-take' }))");
        $this->dispatch('toast', message: __('Stock take cancelled.'), type: 'success');
    }

    public function approve(): void
    {
        Gate::authorize('approve', $this->stockTake);

        $actor = Auth::user();
        $stockTake = $this->stockTake->load('items.item');

        DB::transaction(function () use ($stockTake, $actor) {
            foreach ($stockTake->items as $line) {
                $delta = (float) $line->counted_quantity - (float) $line->system_quantity;

                if ($delta === 0.0) {
                    continue;
                }

                if ($delta < 0) {
                    $currentQty = (float) StockLevel::where('branch_id', $stockTake->branch_id)
                        ->where('item_id', $line->item_id)
                        ->where('batch_id', $line->batch_id)
                        ->sum('quantity_on_hand');

                    if ($currentQty + $delta < 0) {
                        $message = __('Counted quantity for :sku would take stock negative (:qty currently on hand).', [
                            'sku' => $line->item->sku,
                            'qty' => $currentQty,
                        ]);
                        $this->dispatch('toast', message: $message, type: 'error');

                        throw ValidationException::withMessages(['approve' => $message]);
                    }
                }

                StockMovement::create([
                    'tenant_id' => $actor->tenant_id,
                    'branch_id' => $stockTake->branch_id,
                    'item_id' => $line->item_id,
                    'batch_id' => $line->batch_id,
                    'movement_type' => 'adjustment',
                    'quantity' => $delta,
                    'unit_cost' => $line->item->cost_price,
                    'reference_type' => StockTake::class,
                    'reference_id' => $stockTake->id,
                    'reason_code' => 'count_correction',
                    'performed_by' => $actor->id,
                ]);

                $stockLevel = StockLevel::firstOrCreate(
                    [
                        'tenant_id' => $actor->tenant_id,
                        'branch_id' => $stockTake->branch_id,
                        'item_id' => $line->item_id,
                        'batch_id' => $line->batch_id,
                    ],
                    ['quantity_on_hand' => 0],
                );
                $stockLevel->increment('quantity_on_hand', $delta);
            }

            $stockTake->update(['status' => 'approved', 'approved_by' => $actor->id]);
        });

        $this->stockTake = $stockTake->fresh();
        $this->dispatch('toast', message: __('Stock take approved — stock levels updated.'), type: 'success');
    }

    public function reject(): void
    {
        Gate::authorize('reject', $this->stockTake);

        $this->stockTake->update(['status' => 'cancelled', 'approved_by' => Auth::id()]);
        $this->stockTake->refresh();

        $this->dispatch('toast', message: __('Stock take rejected.'), type: 'success');
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
            {{ $stockTake ? $stockTake->reference : __('New Stock Take') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <a href="{{ route('stock-takings.index') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                &larr; {{ __('Back to Stock Takings') }}
            </a>

            @if (! $stockTake && ! $this->openFinancialYear)
                <div class="p-4 bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-400 rounded-md text-sm">
                    {{ __('No financial year is currently open. Ask your Business Owner to open one before starting a stock take.') }}
                </div>
            @elseif (! $stockTake)
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Every active item in your catalog will be pre-populated for counting, with today\'s system quantity shown for reference.') }}
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="stock-take-branch" :value="__('Branch')" />
                            <select wire:model="branch_id" id="stock-take-branch" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                                <option value="">{{ __('Select a branch') }}</option>
                                @foreach ($this->branchOptions as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('branch_id')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="stock-take-notes" :value="__('Notes (optional)')" />
                        <textarea wire:model="notes" id="stock-take-notes" rows="2" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full"></textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>

                    <div class="flex justify-end">
                        <x-primary-button wire:click="start">{{ __('Start Stock Take') }}</x-primary-button>
                    </div>
                </div>
            @else
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-6">
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 text-sm">
                        <div>
                            <div class="text-gray-500 dark:text-gray-400">{{ __('Branch') }}</div>
                            <div class="text-gray-900 dark:text-gray-100">{{ $stockTake->branch->name }}</div>
                        </div>
                        <div>
                            <div class="text-gray-500 dark:text-gray-400">{{ __('Financial Year') }}</div>
                            <div class="text-gray-900 dark:text-gray-100">{{ $stockTake->financialYear->name }}</div>
                        </div>
                        <div>
                            <div class="text-gray-500 dark:text-gray-400">{{ __('Status') }}</div>
                            <span
                                @class([
                                    'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                                    'bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400' => $stockTake->status === 'approved',
                                    'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400' => $stockTake->status === 'cancelled',
                                    'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400' => $stockTake->status === 'pending_approval',
                                    'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-400' => in_array($stockTake->status, ['draft', 'in_progress']),
                                ])
                            >
                                {{ str($stockTake->status)->headline() }}
                            </span>
                        </div>
                        <div>
                            <div class="text-gray-500 dark:text-gray-400">{{ __('Created By') }}</div>
                            <div class="text-gray-900 dark:text-gray-100">{{ $stockTake->createdBy->name }}</div>
                        </div>
                    </div>

                    @if ($stockTake->notes)
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $stockTake->notes }}</p>
                    @endif

                    <div class="overflow-hidden border border-gray-200 dark:border-gray-700 rounded-md">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Item') }}</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Batch') }}</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('System Qty') }}</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Counted Qty') }}</th>
                                    @if ($stockTake->status !== 'in_progress')
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Variance') }}</th>
                                    @endif
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Notes') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach ($stockTake->items()->with(['item', 'batch'])->orderBy('id')->get() as $index => $line)
                                    <tr wire:key="line-{{ $line->id }}">
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $line->item->sku }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $line->batch?->batch_number ?? '—' }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $line->system_quantity }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm">
                                            @if ($stockTake->status === 'in_progress')
                                                <x-text-input wire:model="lines.{{ $index }}.counted_quantity" class="block w-28" type="number" step="0.001" min="0" />
                                            @else
                                                {{ $line->counted_quantity ?? '—' }}
                                            @endif
                                        </td>
                                        @if ($stockTake->status !== 'in_progress')
                                            <td class="px-4 py-2 whitespace-nowrap text-sm font-medium {{ ($line->variance ?? 0) >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                                {{ $line->variance === null ? '—' : (($line->variance >= 0 ? '+' : '').$line->variance) }}
                                            </td>
                                        @endif
                                        <td class="px-4 py-2 text-sm">
                                            @if ($stockTake->status === 'in_progress')
                                                <x-text-input wire:model="lines.{{ $index }}.notes" class="block w-40" type="text" />
                                            @else
                                                {{ $line->notes ?? '—' }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <x-input-error :messages="$errors->get('approve')" class="mt-2" />

                    <div class="flex items-center justify-between border-t border-gray-200 dark:border-gray-700 pt-4">
                        @if ($stockTake->status === 'in_progress')
                            @can('update', $stockTake)
                                <x-secondary-button type="button" x-on:click="$dispatch('open-modal', 'confirm-cancel-stock-take')">
                                    {{ __('Cancel Stock Take') }}
                                </x-secondary-button>

                                <div class="flex gap-3">
                                    <x-secondary-button wire:click="saveProgress">{{ __('Save Progress') }}</x-secondary-button>
                                    <x-primary-button wire:click="complete">{{ __('Mark Complete') }}</x-primary-button>
                                </div>
                            @endcan
                        @elseif ($stockTake->status === 'pending_approval')
                            <div></div>
                            @can('approve', $stockTake)
                                <div class="flex gap-3">
                                    <x-danger-button wire:click="reject">{{ __('Reject') }}</x-danger-button>
                                    <x-primary-button wire:click="approve">{{ __('Approve') }}</x-primary-button>
                                </div>
                            @endcan
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($stockTake)
        <x-confirm-modal
            name="confirm-cancel-stock-take"
            :title="__('Cancel this stock take?')"
            confirm-action="cancel"
            :confirm-label="__('Cancel Stock Take')"
        >
            {{ __('All counted quantities entered so far will be discarded and stock will not be affected.') }}
        </x-confirm-modal>
    @endif
</div>
