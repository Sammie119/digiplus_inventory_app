<?php

use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.super-admin')] class extends Component
{
    public ?int $planId = null;
    public string $name = '';
    public string $max_branches = '';
    public string $max_users = '';
    public string $max_transactions_per_month = '';
    public string $price = '';

    #[Computed]
    public function plans()
    {
        // TenantSubscription is tenant-scoped (BelongsToTenant), so a plain
        // withCount() here would run its correlated subquery through that
        // same global scope and show 0 for every plan when there's no
        // CurrentTenant set (the case for every Super Admin request) —
        // counted manually across all tenants instead.
        $subscriberCounts = TenantSubscription::withoutTenantScope()
            ->where('status', 'active')
            ->selectRaw('plan_id, count(*) as aggregate')
            ->groupBy('plan_id')
            ->pluck('aggregate', 'plan_id');

        return SubscriptionPlan::orderBy('price')->get()->map(function (SubscriptionPlan $plan) use ($subscriberCounts) {
            $plan->subscriber_count = $subscriberCounts->get($plan->id, 0);

            return $plan;
        });
    }

    public function openCreateModal(): void
    {
        $this->reset(['planId', 'name', 'max_branches', 'max_users', 'max_transactions_per_month', 'price']);
        $this->resetErrorBag();
    }

    public function editPlan(int $planId): void
    {
        $plan = SubscriptionPlan::findOrFail($planId);

        $this->planId = $plan->id;
        $this->name = $plan->name;
        $this->max_branches = (string) $plan->max_branches;
        $this->max_users = (string) $plan->max_users;
        $this->max_transactions_per_month = (string) $plan->max_transactions_per_month;
        $this->price = (string) $plan->price;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $plan = $this->planId ? SubscriptionPlan::findOrFail($this->planId) : null;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'max_branches' => ['required', 'integer', 'min:1'],
            'max_users' => ['required', 'integer', 'min:1'],
            'max_transactions_per_month' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        if ($plan) {
            $plan->update($validated);
        } else {
            SubscriptionPlan::create($validated);
        }

        $this->dispatch('toast', message: $plan ? __('Plan updated.') : __('Plan created.'), type: 'success');

        $this->reset(['planId', 'name', 'max_branches', 'max_users', 'max_transactions_per_month', 'price']);
        unset($this->plans);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'plan-form' }))");
    }
}; ?>

<div>
    <x-slot name="header">{{ __('Subscription Plans') }}</x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="flex justify-end">
                <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'plan-form')">
                    <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                    {{ __('Add Plan') }}
                </x-primary-button>
            </div>

            <div id="plans-table" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Max Branches') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Max Users') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Max Tx/mo') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Price') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Subscribers') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->plans as $plan)
                            <tr wire:key="plan-{{ $plan->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $plan->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $plan->max_branches }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $plan->max_users }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($plan->max_transactions_per_month) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($plan->price, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $plan->subscriber_count }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <button
                                        type="button"
                                        title="{{ __('Edit') }}"
                                        aria-label="{{ __('Edit') }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                                        wire:click="editPlan({{ $plan->id }})"
                                        x-on:click="$dispatch('open-modal', 'plan-form')"
                                    ><x-icon.pencil class="h-4 w-4" /></button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No plans yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-modal name="plan-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $planId ? __('Edit Plan') : __('Add Plan') }}
            </h2>

            <div class="mt-4">
                <x-input-label for="plan-name" :value="__('Name')" />
                <x-text-input wire:model="name" id="plan-name" class="block mt-1 w-full" type="text" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="plan-max-branches" :value="__('Max Branches')" />
                    <x-text-input wire:model="max_branches" id="plan-max-branches" class="block mt-1 w-full" type="number" min="1" required />
                    <x-input-error :messages="$errors->get('max_branches')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="plan-max-users" :value="__('Max Users')" />
                    <x-text-input wire:model="max_users" id="plan-max-users" class="block mt-1 w-full" type="number" min="1" required />
                    <x-input-error :messages="$errors->get('max_users')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="plan-max-tx" :value="__('Max Transactions/mo')" />
                    <x-text-input wire:model="max_transactions_per_month" id="plan-max-tx" class="block mt-1 w-full" type="number" min="1" required />
                    <x-input-error :messages="$errors->get('max_transactions_per_month')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="plan-price" :value="__('Price')" />
                    <x-text-input wire:model="price" id="plan-price" class="block mt-1 w-full" type="number" step="0.01" min="0" required />
                    <x-input-error :messages="$errors->get('price')" class="mt-2" />
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
