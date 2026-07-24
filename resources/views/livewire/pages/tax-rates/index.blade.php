<?php

use App\Models\Category;
use App\Models\TaxRate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $taxRateId = null;
    public string $name = '';
    public string $rate_percentage = '';
    public string $applies_to = 'all';
    public ?int $category_id = null;
    public ?int $confirmingDeleteId = null;

    public function mount(): void
    {
        Gate::authorize('tenant-settings.manage');
    }

    #[Computed]
    public function taxRates()
    {
        return TaxRate::with('category')->where('tenant_id', Auth::user()->tenant_id)->orderBy('name')->get();
    }

    #[Computed]
    public function categoryOptions()
    {
        return Category::orderBy('name')->get();
    }

    public function openCreateModal(): void
    {
        $this->reset(['taxRateId', 'name', 'rate_percentage', 'category_id']);
        $this->applies_to = 'all';
        $this->resetErrorBag();
    }

    public function editTaxRate(int $taxRateId): void
    {
        $taxRate = TaxRate::where('tenant_id', Auth::user()->tenant_id)->findOrFail($taxRateId);

        $this->taxRateId = $taxRate->id;
        $this->name = $taxRate->name;
        $this->rate_percentage = (string) $taxRate->rate_percentage;
        $this->applies_to = $taxRate->applies_to;
        $this->category_id = $taxRate->category_id;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $tenantId = Auth::user()->tenant_id;
        $taxRate = $this->taxRateId ? TaxRate::where('tenant_id', $tenantId)->findOrFail($this->taxRateId) : null;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'rate_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'applies_to' => ['required', Rule::in(['all', 'category'])],
            'category_id' => [
                Rule::requiredIf($this->applies_to === 'category'),
                'nullable',
                Rule::in($this->categoryOptions->pluck('id')->all()),
            ],
        ]);

        $validated['category_id'] = $validated['applies_to'] === 'category' ? $validated['category_id'] : null;

        if ($taxRate) {
            $taxRate->update($validated);
        } else {
            TaxRate::create([...$validated, 'tenant_id' => $tenantId]);
        }

        $this->dispatch('toast', message: $taxRate ? __('Tax rate updated.') : __('Tax rate created.'), type: 'success');

        $this->reset(['taxRateId', 'name', 'rate_percentage', 'category_id']);
        unset($this->taxRates);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'tax-rate-form' }))");
    }

    public function confirmDelete(int $taxRateId): void
    {
        $this->confirmingDeleteId = $taxRateId;
    }

    public function delete(): void
    {
        $taxRate = TaxRate::where('tenant_id', Auth::user()->tenant_id)->findOrFail($this->confirmingDeleteId);
        $taxRate->delete();

        $this->confirmingDeleteId = null;
        unset($this->taxRates);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-delete-tax-rate' }))");
        $this->dispatch('toast', message: __('Tax rate deleted.'), type: 'success');
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Tax Rates') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Tax rates are applied automatically at the point of sale.') }}
            </p>

            <div class="flex justify-end">
                <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'tax-rate-form')">
                    <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                    {{ __('Add Tax Rate') }}
                </x-primary-button>
            </div>

            <div id="tax-rates-table" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Rate') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Applies To') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->taxRates as $taxRate)
                            <tr wire:key="tax-rate-{{ $taxRate->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $taxRate->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ rtrim(rtrim(number_format($taxRate->rate_percentage, 2), '0'), '.') }}%</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $taxRate->applies_to === 'category' ? ($taxRate->category?->name ?? __('Category')) : __('All Items') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-1">
                                    <button
                                        type="button"
                                        title="{{ __('Edit') }}"
                                        aria-label="{{ __('Edit') }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                                        wire:click="editTaxRate({{ $taxRate->id }})"
                                        x-on:click="$dispatch('open-modal', 'tax-rate-form')"
                                    ><x-icon.pencil class="h-4 w-4" /></button>
                                    <button
                                        type="button"
                                        title="{{ __('Delete') }}"
                                        aria-label="{{ __('Delete') }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded-md text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/40"
                                        wire:click="confirmDelete({{ $taxRate->id }})"
                                        x-on:click="$dispatch('open-modal', 'confirm-delete-tax-rate')"
                                    ><x-icon.trash class="h-4 w-4" /></button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No tax rates configured yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-modal name="tax-rate-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $taxRateId ? __('Edit Tax Rate') : __('Add Tax Rate') }}
            </h2>

            <div class="mt-4">
                <x-input-label for="tax-rate-name" :value="__('Name')" />
                <x-text-input wire:model="name" id="tax-rate-name" class="block mt-1 w-full" type="text" placeholder="{{ __('e.g. VAT') }}" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="tax-rate-percentage" :value="__('Rate (%)')" />
                <x-text-input wire:model="rate_percentage" id="tax-rate-percentage" class="block mt-1 w-full" type="number" step="0.01" min="0" max="100" required />
                <x-input-error :messages="$errors->get('rate_percentage')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="tax-rate-applies-to" :value="__('Applies To')" />
                <select wire:model.live="applies_to" id="tax-rate-applies-to" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                    <option value="all">{{ __('All Items') }}</option>
                    <option value="category">{{ __('A Specific Category') }}</option>
                </select>
                <x-input-error :messages="$errors->get('applies_to')" class="mt-2" />
            </div>

            @if ($applies_to === 'category')
                <div class="mt-4">
                    <x-input-label for="tax-rate-category" :value="__('Category')" />
                    <select wire:model="category_id" id="tax-rate-category" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                        <option value="">{{ __('Select a category') }}</option>
                        @foreach ($this->categoryOptions as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
                </div>
            @endif

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-confirm-modal
        name="confirm-delete-tax-rate"
        :title="__('Delete this tax rate?')"
        confirm-action="delete"
        :confirm-label="__('Delete')"
    >
        {{ __('It will no longer be applied to new sales. Past sales are unaffected.') }}
    </x-confirm-modal>
</div>
