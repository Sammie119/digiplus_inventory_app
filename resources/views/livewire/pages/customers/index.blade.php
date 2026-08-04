<?php

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public ?int $customerId = null;
    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public string $credit_limit = '';

    public string $paymentAmount = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Customer::class);
    }

    #[Computed]
    public function customers()
    {
        return Customer::orderByDesc('id')->paginate(20);
    }

    private function resetForm(): void
    {
        $this->reset(['customerId', 'name', 'phone', 'email', 'credit_limit']);
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Customer::class);

        $this->resetForm();
        $this->resetErrorBag();
    }

    public function editCustomer(int $customerId): void
    {
        $customer = Customer::findOrFail($customerId);
        Gate::authorize('update', $customer);

        $this->customerId = $customer->id;
        $this->name = $customer->name;
        $this->phone = (string) $customer->phone;
        $this->email = (string) $customer->email;
        $this->credit_limit = $customer->credit_limit !== null ? (string) $customer->credit_limit : '';
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $customer = $this->customerId ? Customer::findOrFail($this->customerId) : null;

        Gate::authorize($customer ? 'update' : 'create', $customer ?? Customer::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validated['phone'] = $validated['phone'] ?: null;
        $validated['email'] = $validated['email'] ?: null;
        $validated['credit_limit'] = $validated['credit_limit'] !== '' ? $validated['credit_limit'] : null;

        if ($customer) {
            $customer->update($validated);
        } else {
            Customer::create([...$validated, 'tenant_id' => Auth::user()->tenant_id, 'credit_balance' => 0]);
        }

        $this->dispatch('toast', message: $customer ? __('Customer updated.') : __('Customer created.'), type: 'success');

        $this->resetForm();
        unset($this->customers);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'customer-form' }))");
    }

    public function resetPaymentForm(): void
    {
        $this->paymentAmount = '';
        $this->resetErrorBag();
    }

    public function recordPayment(int $customerId): void
    {
        $customer = Customer::findOrFail($customerId);
        Gate::authorize('recordPayment', $customer);

        $validated = $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01', 'max:'.max(0.01, (float) $customer->credit_balance)],
        ]);

        DB::transaction(function () use ($customer, $validated) {
            $newBalance = round((float) $customer->credit_balance - (float) $validated['paymentAmount'], 2);

            CustomerLedgerEntry::create([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'entry_type' => 'credit_payment',
                'amount' => $validated['paymentAmount'],
                'balance_after' => $newBalance,
            ]);

            $customer->update(['credit_balance' => $newBalance]);
        });

        $this->dispatch('toast', message: __('Payment recorded.'), type: 'success');

        $this->paymentAmount = '';
        unset($this->customers);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'customer-ledger-{$customerId}' }))");
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Customers') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @can('create', \App\Models\Customer::class)
                <div class="flex justify-end">
                    <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'customer-form')">
                        <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                        {{ __('Add Customer') }}
                    </x-primary-button>
                </div>
            @endcan

            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->customers as $customer)
                    <div wire:key="customer-card-{{ $customer->id }}" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $customer->name }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ $customer->phone ?? $customer->email }}</div>
                            </div>
                            @can('update', $customer)
                                <button
                                    type="button"
                                    title="{{ __('Edit') }}"
                                    aria-label="{{ __('Edit') }}"
                                    class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40 shrink-0"
                                    wire:click="editCustomer({{ $customer->id }})"
                                    x-on:click="$dispatch('open-modal', 'customer-form')"
                                ><x-icon.pencil class="h-4 w-4" /></button>
                            @endcan
                        </div>
                        <div class="mt-3 flex items-center justify-between">
                            <span class="text-sm text-gray-900 dark:text-gray-100">{{ __('Balance') }}: {{ number_format($customer->credit_balance, 2) }}</span>
                            <button type="button" wire:click="resetPaymentForm" x-on:click="$dispatch('open-modal', 'customer-ledger-{{ $customer->id }}')" class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View Ledger') }}</button>
                        </div>
                    </div>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No customers yet.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Phone') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Email') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Credit Limit') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Balance') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->customers as $customer)
                            <tr wire:key="customer-{{ $customer->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $customer->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $customer->phone ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $customer->email ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $customer->credit_limit !== null ? number_format($customer->credit_limit, 2) : __('None') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($customer->credit_balance, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-2">
                                    <button type="button" wire:click="resetPaymentForm" x-on:click="$dispatch('open-modal', 'customer-ledger-{{ $customer->id }}')" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View Ledger') }}</button>
                                    @can('update', $customer)
                                        <button
                                            type="button"
                                            title="{{ __('Edit') }}"
                                            aria-label="{{ __('Edit') }}"
                                            class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                                            wire:click="editCustomer({{ $customer->id }})"
                                            x-on:click="$dispatch('open-modal', 'customer-form')"
                                        ><x-icon.pencil class="h-4 w-4" /></button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No customers yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $this->customers->links() }}
        </div>
    </div>

    @can('create', \App\Models\Customer::class)
    <x-modal name="customer-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $customerId ? __('Edit Customer') : __('Add Customer') }}
            </h2>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-input-label for="customer-name" :value="__('Name')" />
                    <x-text-input wire:model="name" id="customer-name" class="block mt-1 w-full" type="text" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="customer-phone" :value="__('Phone')" />
                    <x-text-input wire:model="phone" id="customer-phone" class="block mt-1 w-full" type="text" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="customer-email" :value="__('Email')" />
                    <x-text-input wire:model="email" id="customer-email" class="block mt-1 w-full" type="email" />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="customer-credit-limit" :value="__('Credit Limit (optional)')" />
                    <x-text-input wire:model="credit_limit" id="customer-credit-limit" class="block mt-1 w-full" type="number" step="0.01" min="0" />
                    <x-input-error :messages="$errors->get('credit_limit')" class="mt-2" />
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
    @endcan

    @foreach ($this->customers as $customer)
        <x-modal name="customer-ledger-{{ $customer->id }}" focusable max-width="lg">
            <div class="p-6 space-y-4 text-sm">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $customer->name }}</h2>
                    <span class="text-gray-900 dark:text-gray-100 font-semibold">{{ __('Balance') }}: {{ number_format($customer->credit_balance, 2) }}</span>
                </div>

                <div class="overflow-hidden border border-gray-200 dark:border-gray-700 rounded-md max-h-64 overflow-y-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40 sticky top-0">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Date') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Type') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Amount') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Balance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($customer->ledgerEntries()->orderByDesc('id')->get() as $entry)
                                <tr>
                                    <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $entry->created_at->format('d M Y, H:i') }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-gray-900 dark:text-gray-100">{{ ucfirst(str_replace('_', ' ', $entry->entry_type)) }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ number_format($entry->amount, 2) }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ number_format($entry->balance_after, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ __('No activity yet.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @can('recordPayment', $customer)
                    @if ($customer->credit_balance > 0)
                        <form wire:submit="recordPayment({{ $customer->id }})" class="flex items-end gap-3 pt-2 border-t border-gray-200 dark:border-gray-700">
                            <div class="flex-1">
                                <x-input-label :value="__('Record Payment')" />
                                <x-text-input wire:model="paymentAmount" class="block mt-1 w-full" type="number" step="0.01" min="0" :max="$customer->credit_balance" />
                                <x-input-error :messages="$errors->get('paymentAmount')" class="mt-2" />
                            </div>
                            <x-primary-button type="submit">{{ __('Record') }}</x-primary-button>
                        </form>
                    @endif
                @endcan

                <div class="flex justify-end pt-2">
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </x-modal>
    @endforeach
</div>
