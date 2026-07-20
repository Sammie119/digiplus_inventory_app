<?php

use App\Models\Branch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $branchId = null;
    public string $name = '';
    public string $code = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Branch::class);
    }

    #[Computed]
    public function branches()
    {
        return Branch::withCount('users')
            ->orderByDesc('is_main_branch')
            ->orderBy('name')
            ->get();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Branch::class);

        $this->reset(['branchId', 'name', 'code']);
        $this->resetErrorBag();
    }

    public function editBranch(int $branchId): void
    {
        $branch = Branch::findOrFail($branchId);
        Gate::authorize('update', $branch);

        $this->branchId = $branch->id;
        $this->name = $branch->name;
        $this->code = $branch->code;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $tenantId = Auth::user()->tenant_id;
        $branch = $this->branchId ? Branch::findOrFail($this->branchId) : null;

        Gate::authorize($branch ? 'update' : 'create', $branch ?? Branch::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('branches')->where('tenant_id', $tenantId)->ignore($branch?->id),
            ],
        ]);
        $validated['code'] = strtoupper($validated['code']);

        if ($branch) {
            $branch->update($validated);
        } else {
            Branch::create([...$validated, 'tenant_id' => $tenantId, 'is_main_branch' => false]);
        }

        $this->reset(['branchId', 'name', 'code']);
        unset($this->branches);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'branch-form' }))");
    }

    public function deactivate(int $branchId): void
    {
        $branch = Branch::withCount('users')->findOrFail($branchId);
        Gate::authorize('delete', $branch);

        if ($branch->is_main_branch) {
            $this->addError('deactivate', 'The main branch cannot be deactivated.');

            return;
        }

        if ($branch->users_count > 0) {
            $this->addError('deactivate', 'Reassign staff out of this branch before deactivating it.');

            return;
        }

        $branch->delete();
        unset($this->branches);
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Branches') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @error('deactivate')
                <div class="p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 rounded-md text-sm">{{ $message }}</div>
            @enderror

            <div class="flex justify-end">
                <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'branch-form')">
                    {{ __('Add Branch') }}
                </x-primary-button>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Code') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Staff') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Main') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->branches as $branch)
                            <tr wire:key="branch-{{ $branch->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $branch->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $branch->code }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $branch->users_count }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $branch->is_main_branch ? __('Yes') : '' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-1">
                                    <button
                                        type="button"
                                        title="{{ __('Edit') }}"
                                        aria-label="{{ __('Edit') }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40 hover:text-indigo-900 dark:hover:text-indigo-300"
                                        wire:click="editBranch({{ $branch->id }})"
                                        x-on:click="$dispatch('open-modal', 'branch-form')"
                                    ><x-icon.pencil class="h-4 w-4" /></button>
                                    @unless ($branch->is_main_branch)
                                        <button
                                            type="button"
                                            title="{{ __('Deactivate') }}"
                                            aria-label="{{ __('Deactivate') }}"
                                            class="inline-flex items-center justify-center p-1.5 rounded-md text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/40 hover:text-red-900 dark:hover:text-red-300"
                                            wire:click="deactivate({{ $branch->id }})"
                                            wire:confirm="{{ __('Deactivate this branch?') }}"
                                        ><x-icon.ban class="h-4 w-4" /></button>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No branches yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-modal name="branch-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $branchId ? __('Edit Branch') : __('Add Branch') }}
            </h2>

            <div class="mt-4">
                <x-input-label for="branch-name" :value="__('Name')" />
                <x-text-input wire:model="name" id="branch-name" class="block mt-1 w-full" type="text" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="branch-code" :value="__('Code')" />
                <x-text-input wire:model="code" id="branch-code" class="block mt-1 w-full" type="text" required />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
