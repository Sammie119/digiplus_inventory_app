<?php

use App\Models\UnitOfMeasure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $unitId = null;
    public string $name = '';
    public string $abbreviation = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', UnitOfMeasure::class);
    }

    #[Computed]
    public function units()
    {
        return UnitOfMeasure::orderBy('name')->get();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', UnitOfMeasure::class);

        $this->reset(['unitId', 'name', 'abbreviation']);
        $this->resetErrorBag();
    }

    public function editUnit(int $unitId): void
    {
        $unit = UnitOfMeasure::findOrFail($unitId);
        Gate::authorize('update', $unit);

        $this->unitId = $unit->id;
        $this->name = $unit->name;
        $this->abbreviation = $unit->abbreviation;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $unit = $this->unitId ? UnitOfMeasure::findOrFail($this->unitId) : null;

        Gate::authorize($unit ? 'update' : 'create', $unit ?? UnitOfMeasure::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'abbreviation' => ['required', 'string', 'max:20'],
        ]);

        if ($unit) {
            $unit->update($validated);
        } else {
            UnitOfMeasure::create([...$validated, 'tenant_id' => Auth::user()->tenant_id]);
        }

        $this->reset(['unitId', 'name', 'abbreviation']);
        unset($this->units);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'unit-form' }))");
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Units of Measure') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="flex items-center justify-between gap-2">
                <a href="{{ route('products.index') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                    &larr; {{ __('Back to Products') }}
                </a>

                @can('create', \App\Models\UnitOfMeasure::class)
                    <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'unit-form')">
                        <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                        {{ __('Add Unit') }}
                    </x-primary-button>
                @endcan
            </div>

            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->units as $unit)
                    <div wire:key="unit-card-{{ $unit->id }}" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $unit->name }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $unit->abbreviation }}</div>
                        </div>
                        @can('update', $unit)
                            <button
                                type="button"
                                title="{{ __('Edit') }}"
                                aria-label="{{ __('Edit') }}"
                                class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40 shrink-0"
                                wire:click="editUnit({{ $unit->id }})"
                                x-on:click="$dispatch('open-modal', 'unit-form')"
                            ><x-icon.pencil class="h-4 w-4" /></button>
                        @endcan
                    </div>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No units yet.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Abbreviation') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->units as $unit)
                            <tr wire:key="unit-{{ $unit->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $unit->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $unit->abbreviation }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    @can('update', $unit)
                                        <button
                                            type="button"
                                            title="{{ __('Edit') }}"
                                            aria-label="{{ __('Edit') }}"
                                            class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                                            wire:click="editUnit({{ $unit->id }})"
                                            x-on:click="$dispatch('open-modal', 'unit-form')"
                                        ><x-icon.pencil class="h-4 w-4" /></button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No units yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @can('create', \App\Models\UnitOfMeasure::class)
    <x-modal name="unit-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $unitId ? __('Edit Unit') : __('Add Unit') }}
            </h2>

            <div class="mt-4">
                <x-input-label for="unit-name" :value="__('Name')" />
                <x-text-input wire:model="name" id="unit-name" class="block mt-1 w-full" type="text" placeholder="Piece" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="unit-abbreviation" :value="__('Abbreviation')" />
                <x-text-input wire:model="abbreviation" id="unit-abbreviation" class="block mt-1 w-full" type="text" placeholder="pc" required />
                <x-input-error :messages="$errors->get('abbreviation')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
    @endcan
</div>
