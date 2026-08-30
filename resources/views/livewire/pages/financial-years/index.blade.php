<?php

use App\Models\FinancialYear;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $name = '';
    public string $start_date = '';
    public string $end_date = '';
    public ?int $confirmingCloseId = null;

    public function mount(): void
    {
        Gate::authorize('tenant-settings.manage');
    }

    #[Computed]
    public function financialYears()
    {
        return FinancialYear::where('tenant_id', Auth::user()->tenant_id)->orderByDesc('start_date')->get();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('tenant-settings.manage');

        $this->reset(['name', 'start_date', 'end_date']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        Gate::authorize('tenant-settings.manage');

        $tenantId = Auth::user()->tenant_id;

        if (FinancialYear::where('tenant_id', $tenantId)->where('status', 'open')->exists()) {
            $this->addError('name', __('A financial year is already open. Close it before opening a new one.'));

            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        $overlaps = FinancialYear::where('tenant_id', $tenantId)
            ->where('start_date', '<=', $validated['end_date'])
            ->where('end_date', '>=', $validated['start_date'])
            ->exists();

        if ($overlaps) {
            $this->addError('start_date', __('This date range overlaps with an existing financial year.'));

            return;
        }

        FinancialYear::create([...$validated, 'tenant_id' => $tenantId, 'status' => 'open']);

        $this->dispatch('toast', message: __('Financial year created.'), type: 'success');

        $this->reset(['name', 'start_date', 'end_date']);
        unset($this->financialYears);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'financial-year-form' }))");
    }

    public function confirmClose(int $id): void
    {
        $this->confirmingCloseId = $id;
    }

    public function close(): void
    {
        Gate::authorize('tenant-settings.manage');

        $financialYear = FinancialYear::where('tenant_id', Auth::user()->tenant_id)->findOrFail($this->confirmingCloseId);

        $this->confirmingCloseId = null;
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-close-financial-year' }))");

        if ($financialYear->stockTakes()->whereIn('status', ['draft', 'in_progress', 'pending_approval'])->exists()) {
            $this->dispatch('toast', message: __('Resolve all pending stock takes before closing this financial year.'), type: 'error');

            return;
        }

        $financialYear->update(['status' => 'closed', 'closed_by' => Auth::id(), 'closed_at' => now()]);

        unset($this->financialYears);
        $this->dispatch('toast', message: __('Financial year closed.'), type: 'success');
    }

    public function reopen(int $id): void
    {
        Gate::authorize('tenant-settings.manage');

        $tenantId = Auth::user()->tenant_id;
        $financialYear = FinancialYear::where('tenant_id', $tenantId)->findOrFail($id);

        if (FinancialYear::where('tenant_id', $tenantId)->where('status', 'open')->exists()) {
            $this->dispatch('toast', message: __('Close the currently open financial year first.'), type: 'error');

            return;
        }

        $financialYear->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);

        unset($this->financialYears);
        $this->dispatch('toast', message: __('Financial year reopened.'), type: 'success');
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Financial Years') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Only one financial year can be open at a time. Stock takes are recorded against the currently open year.') }}
            </p>

            <div class="flex justify-end">
                <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'financial-year-form')">
                    <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                    {{ __('Add Financial Year') }}
                </x-primary-button>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Period') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->financialYears as $financialYear)
                            <tr wire:key="financial-year-{{ $financialYear->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $financialYear->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $financialYear->start_date->format('d M Y') }} &ndash; {{ $financialYear->end_date->format('d M Y') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span
                                        @class([
                                            'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                                            'bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400' => $financialYear->status === 'open',
                                            'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' => $financialYear->status === 'closed',
                                        ])
                                    >
                                        {{ ucfirst($financialYear->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    @if ($financialYear->status === 'open')
                                        <button type="button" wire:click="confirmClose({{ $financialYear->id }})" x-on:click="$dispatch('open-modal', 'confirm-close-financial-year')" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Close') }}</button>
                                    @else
                                        <button type="button" wire:click="reopen({{ $financialYear->id }})" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Reopen') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No financial years yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-modal name="financial-year-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Add Financial Year') }}</h2>

            <div class="mt-4">
                <x-input-label for="fy-name" :value="__('Name')" />
                <x-text-input wire:model="name" id="fy-name" class="block mt-1 w-full" type="text" placeholder="{{ __('e.g. FY2026') }}" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="fy-start" :value="__('Start Date')" />
                    <x-text-input wire:model="start_date" id="fy-start" class="block mt-1 w-full" type="date" required />
                    <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="fy-end" :value="__('End Date')" />
                    <x-text-input wire:model="end_date" id="fy-end" class="block mt-1 w-full" type="date" required />
                    <x-input-error :messages="$errors->get('end_date')" class="mt-2" />
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-confirm-modal
        name="confirm-close-financial-year"
        :title="__('Close this financial year?')"
        confirm-action="close"
        :confirm-label="__('Close')"
    >
        {{ __('No new stock takes can be recorded against it once closed. Any pending stock takes must be resolved first.') }}
    </x-confirm-modal>
</div>
