<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $productId = null;
    public string $name = '';
    public ?int $category_id = null;
    public string $description = '';
    public bool $has_variants = false;

    public function mount(): void
    {
        Gate::authorize('viewAny', Product::class);
    }

    #[Computed]
    public function products()
    {
        return Product::with('category')->withCount('items')->orderBy('name')->get();
    }

    #[Computed]
    public function categoryOptions()
    {
        return Category::orderBy('name')->get();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Product::class);

        $this->reset(['productId', 'name', 'category_id', 'description', 'has_variants']);
        $this->resetErrorBag();
    }

    public function editProduct(int $productId): void
    {
        $product = Product::findOrFail($productId);
        Gate::authorize('update', $product);

        $this->productId = $product->id;
        $this->name = $product->name;
        $this->category_id = $product->category_id;
        $this->description = (string) $product->description;
        $this->has_variants = $product->has_variants;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $product = $this->productId ? Product::findOrFail($this->productId) : null;

        Gate::authorize($product ? 'update' : 'create', $product ?? Product::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'has_variants' => ['boolean'],
        ]);

        if ($product) {
            $product->update($validated);
        } else {
            Product::create([...$validated, 'tenant_id' => Auth::user()->tenant_id]);
        }

        $this->dispatch('toast', message: $product ? __('Product updated.') : __('Product created.'), type: 'success');

        $this->reset(['productId', 'name', 'category_id', 'description', 'has_variants']);
        unset($this->products);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'product-form' }))");
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Products') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="flex justify-end gap-2">
                <a
                    href="{{ route('categories.index') }}"
                    wire:navigate
                    class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150"
                >{{ __('Categories') }}</a>
                <a
                    href="{{ route('units-of-measure.index') }}"
                    wire:navigate
                    class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150"
                >{{ __('Units') }}</a>
                @can('create', \App\Models\Product::class)
                    <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'product-form')">
                        <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                        {{ __('Add Product') }}
                    </x-primary-button>
                @endcan
            </div>

            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->products as $product)
                    <a
                        wire:key="product-card-{{ $product->id }}"
                        href="{{ route('products.items', $product) }}"
                        wire:navigate
                        class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $product->name }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $product->category->name }}</div>
                            </div>
                            @can('update', $product)
                                <button
                                    type="button"
                                    title="{{ __('Edit') }}"
                                    aria-label="{{ __('Edit') }}"
                                    class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40 shrink-0"
                                    wire:click.stop.prevent="editProduct({{ $product->id }})"
                                    x-on:click.stop.prevent="$dispatch('open-modal', 'product-form')"
                                ><x-icon.pencil class="h-4 w-4" /></button>
                            @endcan
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Items') }}</dt>
                                <dd class="text-gray-900 dark:text-gray-100">{{ $product->items_count }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Variants') }}</dt>
                                <dd class="text-gray-900 dark:text-gray-100">{{ $product->has_variants ? __('Yes') : __('No') }}</dd>
                            </div>
                        </dl>
                    </a>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No products yet.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Category') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Variants') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Items') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->products as $product)
                            <tr wire:key="product-{{ $product->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <a href="{{ route('products.items', $product) }}" wire:navigate class="hover:text-indigo-600 dark:hover:text-indigo-400">{{ $product->name }}</a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $product->category->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $product->has_variants ? __('Yes') : __('No') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $product->items_count }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-1">
                                    <a
                                        href="{{ route('products.items', $product) }}"
                                        wire:navigate
                                        title="{{ __('Items') }}"
                                        aria-label="{{ __('Items') }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"
                                    ><x-icon.box class="h-4 w-4" /></a>
                                    @can('update', $product)
                                        <button
                                            type="button"
                                            title="{{ __('Edit') }}"
                                            aria-label="{{ __('Edit') }}"
                                            class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                                            wire:click="editProduct({{ $product->id }})"
                                            x-on:click="$dispatch('open-modal', 'product-form')"
                                        ><x-icon.pencil class="h-4 w-4" /></button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No products yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @can('create', \App\Models\Product::class)
    <x-modal name="product-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $productId ? __('Edit Product') : __('Add Product') }}
            </h2>

            <div class="mt-4">
                <x-input-label for="product-name" :value="__('Name')" />
                <x-text-input wire:model="name" id="product-name" class="block mt-1 w-full" type="text" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="product-category" :value="__('Category')" />
                <select wire:model="category_id" id="product-category" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                    <option value="">{{ __('Select a category') }}</option>
                    @foreach ($this->categoryOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="product-description" :value="__('Description')" />
                <textarea wire:model="description" id="product-description" rows="3" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full"></textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div class="mt-4 flex items-center gap-2">
                <input wire:model="has_variants" id="product-has-variants" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                <label for="product-has-variants" class="text-sm text-gray-700 dark:text-gray-300">{{ __('This product has variants (e.g. size, color)') }}</label>
            </div>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Add the individual SKUs from the Items page after saving.') }}
            </p>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
    @endcan
</div>
