<?php

use App\Models\Batch;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Product $product;

    public ?int $itemId = null;
    public string $sku = '';
    public string $barcode = '';
    public string $size = '';
    public string $color = '';
    public ?int $base_unit_id = null;
    public ?int $purchase_unit_id = null;
    public ?string $purchase_unit_factor = null;
    public string $cost_price = '0';
    public string $selling_price = '';
    public int $reorder_level = 0;
    public bool $track_batches = false;
    public string $batch_number = '';
    public ?string $expiry_date = null;
    public ?int $confirmingDeactivateId = null;

    public function mount(Product $product): void
    {
        Gate::authorize('viewAny', Product::class);

        $this->product = $product;
    }

    #[Computed]
    public function items()
    {
        return $this->product->items()->with('baseUnit')->orderBy('sku')->get();
    }

    #[Computed]
    public function unitOptions()
    {
        return UnitOfMeasure::orderBy('name')->get();
    }

    private function resetForm(): void
    {
        $this->reset([
            'itemId', 'sku', 'barcode', 'size', 'color', 'base_unit_id',
            'purchase_unit_id', 'purchase_unit_factor', 'selling_price',
            'reorder_level', 'track_batches', 'batch_number', 'expiry_date',
        ]);
        $this->cost_price = '0';
    }

    public function openCreateModal(): void
    {
        Gate::authorize('update', $this->product);

        $this->resetForm();
        $this->resetErrorBag();
    }

    public function editItem(int $itemId): void
    {
        Gate::authorize('update', $this->product);

        $item = $this->product->items()->findOrFail($itemId);

        $this->itemId = $item->id;
        $this->sku = $item->sku;
        $this->barcode = (string) $item->barcode;
        $this->size = (string) ($item->variant_attributes['size'] ?? '');
        $this->color = (string) ($item->variant_attributes['color'] ?? '');
        $this->base_unit_id = $item->base_unit_id;
        $this->purchase_unit_id = $item->purchase_unit_id;
        $this->purchase_unit_factor = $item->purchase_unit_factor;
        $this->cost_price = (string) $item->cost_price;
        $this->selling_price = (string) $item->selling_price;
        $this->reorder_level = $item->reorder_level;
        $this->track_batches = $item->track_batches;

        $latestBatch = $item->track_batches ? $item->batches()->latest('received_date')->first() : null;
        $this->batch_number = $latestBatch?->batch_number ?? '';
        $this->expiry_date = $latestBatch?->expiry_date?->toDateString();

        $this->resetErrorBag();
    }

    public function save(): void
    {
        Gate::authorize('update', $this->product);

        $item = $this->itemId ? $this->product->items()->findOrFail($this->itemId) : null;
        $tenantId = Auth::user()->tenant_id;

        $validated = $this->validate([
            'sku' => ['required', 'string', 'max:100', Rule::unique('items')->where('tenant_id', $tenantId)->ignore($item?->id)],
            'barcode' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'base_unit_id' => ['required', 'exists:units_of_measure,id'],
            'purchase_unit_id' => ['nullable', 'exists:units_of_measure,id'],
            'purchase_unit_factor' => ['nullable', 'numeric', 'min:0.001', 'required_with:purchase_unit_id'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'integer', 'min:0'],
            'track_batches' => ['boolean'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $variantAttributes = array_filter([
            'size' => $validated['size'] ?: null,
            'color' => $validated['color'] ?: null,
        ]);

        $attributes = [
            'sku' => $validated['sku'],
            'barcode' => $validated['barcode'] ?: null,
            'variant_attributes' => $variantAttributes ?: null,
            'base_unit_id' => $validated['base_unit_id'],
            'purchase_unit_id' => $validated['purchase_unit_id'] ?: null,
            'purchase_unit_factor' => $validated['purchase_unit_id'] ? $validated['purchase_unit_factor'] : null,
            'cost_price' => $validated['cost_price'],
            'selling_price' => $validated['selling_price'],
            'reorder_level' => $validated['reorder_level'],
            'track_batches' => $validated['track_batches'],
        ];

        if ($item) {
            $item->update($attributes);
        } else {
            $item = $this->product->items()->create([...$attributes, 'tenant_id' => $tenantId]);
        }

        if ($validated['track_batches'] && filled($validated['batch_number'])) {
            Batch::updateOrCreate(
                ['item_id' => $item->id, 'batch_number' => $validated['batch_number']],
                [
                    'tenant_id' => $tenantId,
                    'expiry_date' => $validated['expiry_date'] ?: null,
                    'received_date' => now(),
                ],
            );
        }

        $this->dispatch('toast', message: $this->itemId ? __('Item updated.') : __('Item created.'), type: 'success');

        $this->resetForm();
        unset($this->items);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'item-form' }))");
    }

    public function confirmDeactivate(int $itemId): void
    {
        Gate::authorize('update', $this->product);

        $this->confirmingDeactivateId = $itemId;
    }

    public function deactivate(): void
    {
        Gate::authorize('update', $this->product);

        $item = $this->product->items()->findOrFail($this->confirmingDeactivateId);
        $item->delete();

        $this->confirmingDeactivateId = null;
        unset($this->items);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-deactivate-item' }))");
        $this->dispatch('toast', message: __('Item deactivated.'), type: 'success');
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight truncate">
            {{ $product->name }} &mdash; {{ __('Items') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="flex items-center justify-between gap-2">
                <a href="{{ route('products.index') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                    &larr; {{ __('Back to Products') }}
                </a>

                @can('update', $product)
                    <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'item-form')">
                        <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                        {{ __('Add Item') }}
                    </x-primary-button>
                @endcan
            </div>

            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->items as $item)
                    <div wire:key="item-card-{{ $item->id }}" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $item->sku }}</div>
                                @if ($item->variant_attributes)
                                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ collect($item->variant_attributes)->map(fn ($v, $k) => ucfirst($k).': '.$v)->join(', ') }}</div>
                                @endif
                            </div>
                            @can('update', $product)
                                <div class="flex items-center gap-1 shrink-0">
                                    <button
                                        type="button"
                                        title="{{ __('Edit') }}"
                                        aria-label="{{ __('Edit') }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                                        wire:click="editItem({{ $item->id }})"
                                        x-on:click="$dispatch('open-modal', 'item-form')"
                                    ><x-icon.pencil class="h-4 w-4" /></button>
                                    <button
                                        type="button"
                                        title="{{ __('Deactivate') }}"
                                        aria-label="{{ __('Deactivate') }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded-md text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/40"
                                        wire:click="confirmDeactivate({{ $item->id }})"
                                        x-on:click="$dispatch('open-modal', 'confirm-deactivate-item')"
                                    ><x-icon.ban class="h-4 w-4" /></button>
                                </div>
                            @endcan
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Unit') }}</dt>
                                <dd class="text-gray-900 dark:text-gray-100">{{ $item->baseUnit->abbreviation }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Reorder Level') }}</dt>
                                <dd class="text-gray-900 dark:text-gray-100">{{ $item->reorder_level }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Cost') }}</dt>
                                <dd class="text-gray-900 dark:text-gray-100">{{ number_format($item->cost_price, 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Price') }}</dt>
                                <dd class="text-gray-900 dark:text-gray-100">{{ number_format($item->selling_price, 2) }}</dd>
                            </div>
                        </dl>
                    </div>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No items yet.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('SKU') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Variant') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Unit') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Cost') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Price') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Reorder') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->items as $item)
                            <tr wire:key="item-{{ $item->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    {{ $item->sku }}
                                    @if ($item->barcode)
                                        <span class="block text-xs text-gray-400 dark:text-gray-500">{{ $item->barcode }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    {{ $item->variant_attributes ? collect($item->variant_attributes)->map(fn ($v, $k) => ucfirst($k).': '.$v)->join(', ') : '—' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $item->baseUnit->abbreviation }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($item->cost_price, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($item->selling_price, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $item->reorder_level }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-1">
                                    @can('update', $product)
                                        <button
                                            type="button"
                                            title="{{ __('Edit') }}"
                                            aria-label="{{ __('Edit') }}"
                                            class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                                            wire:click="editItem({{ $item->id }})"
                                            x-on:click="$dispatch('open-modal', 'item-form')"
                                        ><x-icon.pencil class="h-4 w-4" /></button>
                                        <button
                                            type="button"
                                            title="{{ __('Deactivate') }}"
                                            aria-label="{{ __('Deactivate') }}"
                                            class="inline-flex items-center justify-center p-1.5 rounded-md text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/40"
                                            wire:click="confirmDeactivate({{ $item->id }})"
                                            x-on:click="$dispatch('open-modal', 'confirm-deactivate-item')"
                                        ><x-icon.ban class="h-4 w-4" /></button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No items yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @can('update', $product)
    <x-modal name="item-form" focusable max-width="lg">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $itemId ? __('Edit Item') : __('Add Item') }}
            </h2>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="item-sku" :value="__('SKU')" />
                    <x-text-input wire:model="sku" id="item-sku" class="block mt-1 w-full" type="text" required />
                    <x-input-error :messages="$errors->get('sku')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="item-barcode" :value="__('Barcode')" />
                    <x-text-input wire:model="barcode" id="item-barcode" class="block mt-1 w-full" type="text" />
                    <x-input-error :messages="$errors->get('barcode')" class="mt-2" />
                </div>

                @if ($product->has_variants)
                    <div>
                        <x-input-label for="item-size" :value="__('Size')" />
                        <x-text-input wire:model="size" id="item-size" class="block mt-1 w-full" type="text" />
                        <x-input-error :messages="$errors->get('size')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="item-color" :value="__('Color')" />
                        <x-text-input wire:model="color" id="item-color" class="block mt-1 w-full" type="text" />
                        <x-input-error :messages="$errors->get('color')" class="mt-2" />
                    </div>
                @endif

                <div>
                    <x-input-label for="item-base-unit" :value="__('Base Unit')" />
                    <select wire:model="base_unit_id" id="item-base-unit" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                        <option value="">{{ __('Select a unit') }}</option>
                        @foreach ($this->unitOptions as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->abbreviation }})</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('base_unit_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="item-purchase-unit" :value="__('Purchase Unit (optional)')" />
                    <select wire:model="purchase_unit_id" id="item-purchase-unit" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($this->unitOptions as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->abbreviation }})</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('purchase_unit_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="item-purchase-unit-factor" :value="__('Units per Purchase Unit')" />
                    <x-text-input wire:model="purchase_unit_factor" id="item-purchase-unit-factor" class="block mt-1 w-full" type="number" step="0.001" placeholder="e.g. 24" />
                    <x-input-error :messages="$errors->get('purchase_unit_factor')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="item-reorder-level" :value="__('Reorder Level')" />
                    <x-text-input wire:model="reorder_level" id="item-reorder-level" class="block mt-1 w-full" type="number" min="0" required />
                    <x-input-error :messages="$errors->get('reorder_level')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="item-cost-price" :value="__('Cost Price')" />
                    <x-text-input wire:model="cost_price" id="item-cost-price" class="block mt-1 w-full" type="number" step="0.01" min="0" required />
                    <x-input-error :messages="$errors->get('cost_price')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="item-selling-price" :value="__('Selling Price')" />
                    <x-text-input wire:model="selling_price" id="item-selling-price" class="block mt-1 w-full" type="number" step="0.01" min="0" required />
                    <x-input-error :messages="$errors->get('selling_price')" class="mt-2" />
                </div>
            </div>

            <div class="mt-4 flex items-center gap-2">
                <input wire:model.live="track_batches" id="item-track-batches" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                <label for="item-track-batches" class="text-sm text-gray-700 dark:text-gray-300">{{ __('Track batches and expiry dates for this item') }}</label>
            </div>

            @if ($track_batches)
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="item-batch-number" :value="__('Batch Number (optional)')" />
                        <x-text-input wire:model="batch_number" id="item-batch-number" class="block mt-1 w-full" type="text" />
                        <x-input-error :messages="$errors->get('batch_number')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="item-expiry-date" :value="__('Expiry Date (optional)')" />
                        <x-text-input wire:model="expiry_date" id="item-expiry-date" class="block mt-1 w-full" type="date" />
                        <x-input-error :messages="$errors->get('expiry_date')" class="mt-2" />
                    </div>
                </div>
            @endif

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-confirm-modal
        name="confirm-deactivate-item"
        :title="__('Deactivate this item?')"
        confirm-action="deactivate"
        :confirm-label="__('Deactivate')"
    >
        {{ __('This item will no longer be available for sale.') }}
    </x-confirm-modal>
    @endcan
</div>
