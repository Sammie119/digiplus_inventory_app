<?php

use App\Models\Category;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $categoryId = null;
    public string $name = '';
    public ?int $parent_category_id = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', Category::class);
    }

    #[Computed]
    public function categories()
    {
        return Category::with('parent')->orderBy('name')->get();
    }

    #[Computed]
    public function parentOptions()
    {
        return Category::where('id', '!=', $this->categoryId ?? 0)
            ->orderBy('name')
            ->get();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Category::class);

        $this->reset(['categoryId', 'name', 'parent_category_id']);
        $this->resetErrorBag();
    }

    public function editCategory(int $categoryId): void
    {
        $category = Category::findOrFail($categoryId);
        Gate::authorize('update', $category);

        $this->categoryId = $category->id;
        $this->name = $category->name;
        $this->parent_category_id = $category->parent_category_id;
        $this->resetErrorBag();
        unset($this->parentOptions);
    }

    public function save(): void
    {
        $category = $this->categoryId ? Category::findOrFail($this->categoryId) : null;

        Gate::authorize($category ? 'update' : 'create', $category ?? Category::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_category_id' => ['nullable', 'integer', Rule::in($this->parentOptions->pluck('id')->all())],
        ]);

        if ($category) {
            $category->update($validated);
        } else {
            Category::create([...$validated, 'tenant_id' => Auth::user()->tenant_id]);
        }

        $this->dispatch('toast', message: $category ? __('Category updated.') : __('Category created.'), type: 'success');

        $this->reset(['categoryId', 'name', 'parent_category_id']);
        unset($this->categories);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'category-form' }))");
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Categories') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="flex items-center justify-between gap-2">
                <a href="{{ route('products.index') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                    &larr; {{ __('Back to Products') }}
                </a>

                @can('create', \App\Models\Category::class)
                    <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'category-form')">
                        <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                        {{ __('Add Category') }}
                    </x-primary-button>
                @endcan
            </div>

            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->categories as $category)
                    <div wire:key="category-card-{{ $category->id }}" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $category->name }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $category->parent?->name ?? __('No parent') }}</div>
                        </div>
                        @can('update', $category)
                            <button
                                type="button"
                                title="{{ __('Edit') }}"
                                aria-label="{{ __('Edit') }}"
                                class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40 shrink-0"
                                wire:click="editCategory({{ $category->id }})"
                                x-on:click="$dispatch('open-modal', 'category-form')"
                            ><x-icon.pencil class="h-4 w-4" /></button>
                        @endcan
                    </div>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No categories yet.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Parent') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->categories as $category)
                            <tr wire:key="category-{{ $category->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $category->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $category->parent?->name ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    @can('update', $category)
                                        <button
                                            type="button"
                                            title="{{ __('Edit') }}"
                                            aria-label="{{ __('Edit') }}"
                                            class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                                            wire:click="editCategory({{ $category->id }})"
                                            x-on:click="$dispatch('open-modal', 'category-form')"
                                        ><x-icon.pencil class="h-4 w-4" /></button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No categories yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @can('create', \App\Models\Category::class)
    <x-modal name="category-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $categoryId ? __('Edit Category') : __('Add Category') }}
            </h2>

            <div class="mt-4">
                <x-input-label for="category-name" :value="__('Name')" />
                <x-text-input wire:model="name" id="category-name" class="block mt-1 w-full" type="text" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="category-parent" :value="__('Parent category')" />
                <select wire:model="parent_category_id" id="category-parent" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($this->parentOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('parent_category_id')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
    @endcan
</div>
