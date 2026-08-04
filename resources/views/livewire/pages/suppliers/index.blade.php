<?php

use App\Models\Supplier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public ?int $supplierId = null;
    public string $name = '';
    public string $contact_person = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Supplier::class);
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::orderByDesc('id')->paginate(20);
    }

    private function resetForm(): void
    {
        $this->reset(['supplierId', 'name', 'contact_person', 'phone', 'email', 'address']);
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Supplier::class);

        $this->resetForm();
        $this->resetErrorBag();
    }

    public function editSupplier(int $supplierId): void
    {
        $supplier = Supplier::findOrFail($supplierId);
        Gate::authorize('update', $supplier);

        $this->supplierId = $supplier->id;
        $this->name = $supplier->name;
        $this->contact_person = (string) $supplier->contact_person;
        $this->phone = (string) $supplier->phone;
        $this->email = (string) $supplier->email;
        $this->address = (string) $supplier->address;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $supplier = $this->supplierId ? Supplier::findOrFail($this->supplierId) : null;

        Gate::authorize($supplier ? 'update' : 'create', $supplier ?? Supplier::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (['contact_person', 'phone', 'email', 'address'] as $optional) {
            $validated[$optional] = $validated[$optional] ?: null;
        }

        if ($supplier) {
            $supplier->update($validated);
        } else {
            Supplier::create([...$validated, 'tenant_id' => Auth::user()->tenant_id]);
        }

        $this->dispatch('toast', message: $supplier ? __('Supplier updated.') : __('Supplier created.'), type: 'success');

        $this->resetForm();
        unset($this->suppliers);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'supplier-form' }))");
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Suppliers') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @can('create', \App\Models\Supplier::class)
                <div class="flex justify-end">
                    <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'supplier-form')">
                        <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                        {{ __('Add Supplier') }}
                    </x-primary-button>
                </div>
            @endcan

            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->suppliers as $supplier)
                    <div wire:key="supplier-card-{{ $supplier->id }}" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $supplier->name }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ $supplier->phone ?? $supplier->email }}</div>
                            </div>
                            @can('update', $supplier)
                                <button
                                    type="button"
                                    title="{{ __('Edit') }}"
                                    aria-label="{{ __('Edit') }}"
                                    class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40 shrink-0"
                                    wire:click="editSupplier({{ $supplier->id }})"
                                    x-on:click="$dispatch('open-modal', 'supplier-form')"
                                ><x-icon.pencil class="h-4 w-4" /></button>
                            @endcan
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Contact') }}</dt>
                                <dd class="text-gray-900 dark:text-gray-100">{{ $supplier->contact_person ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Outstanding') }}</dt>
                                <dd class="text-gray-900 dark:text-gray-100">{{ number_format($supplier->outstanding_balance, 2) }}</dd>
                            </div>
                        </dl>
                    </div>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No suppliers yet.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Contact') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Phone') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Email') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Outstanding') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->suppliers as $supplier)
                            <tr wire:key="supplier-{{ $supplier->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $supplier->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $supplier->contact_person ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $supplier->phone ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $supplier->email ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($supplier->outstanding_balance, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    @can('update', $supplier)
                                        <button
                                            type="button"
                                            title="{{ __('Edit') }}"
                                            aria-label="{{ __('Edit') }}"
                                            class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                                            wire:click="editSupplier({{ $supplier->id }})"
                                            x-on:click="$dispatch('open-modal', 'supplier-form')"
                                        ><x-icon.pencil class="h-4 w-4" /></button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No suppliers yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $this->suppliers->links() }}
        </div>
    </div>

    @can('create', \App\Models\Supplier::class)
    <x-modal name="supplier-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $supplierId ? __('Edit Supplier') : __('Add Supplier') }}
            </h2>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-input-label for="supplier-name" :value="__('Name')" />
                    <x-text-input wire:model="name" id="supplier-name" class="block mt-1 w-full" type="text" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="supplier-contact" :value="__('Contact Person')" />
                    <x-text-input wire:model="contact_person" id="supplier-contact" class="block mt-1 w-full" type="text" />
                    <x-input-error :messages="$errors->get('contact_person')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="supplier-phone" :value="__('Phone')" />
                    <x-text-input wire:model="phone" id="supplier-phone" class="block mt-1 w-full" type="text" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="supplier-email" :value="__('Email')" />
                    <x-text-input wire:model="email" id="supplier-email" class="block mt-1 w-full" type="email" />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="supplier-address" :value="__('Address')" />
                    <x-text-input wire:model="address" id="supplier-address" class="block mt-1 w-full" type="text" />
                    <x-input-error :messages="$errors->get('address')" class="mt-2" />
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
    @endcan
</div>
