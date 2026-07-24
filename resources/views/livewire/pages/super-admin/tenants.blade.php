<?php

use App\Actions\Tenants\RegisterTenantOwner;
use App\Models\Branch;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.super-admin')] class extends Component
{
    public ?int $subscriptionTenantId = null;
    public string $plan_id = '';
    public string $period_start = '';
    public string $period_end = '';
    public ?int $confirmingSuspendId = null;

    public string $new_business_name = '';
    public string $new_owner_name = '';
    public string $new_owner_email = '';

    #[Computed]
    public function tenants()
    {
        $branchCounts = Branch::withoutTenantScope()
            ->selectRaw('tenant_id, count(*) as aggregate')
            ->groupBy('tenant_id')
            ->pluck('aggregate', 'tenant_id');

        return Tenant::withCount('users')
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant) use ($branchCounts) {
                $tenant->branches_count = $branchCounts->get($tenant->id, 0);

                $subscription = TenantSubscription::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->with('plan')
                    ->latest('period_end')
                    ->first();

                return [
                    'tenant' => $tenant,
                    'subscription' => $subscription,
                ];
            });
    }

    #[Computed]
    public function planOptions()
    {
        return SubscriptionPlan::orderBy('price')->get();
    }

    public function openSubscriptionModal(int $tenantId): void
    {
        $this->subscriptionTenantId = $tenantId;
        $this->plan_id = '';
        $this->period_start = now()->toDateString();
        $this->period_end = now()->addMonth()->toDateString();
        $this->resetErrorBag();
    }

    public function openCreateTenantModal(): void
    {
        $this->new_business_name = '';
        $this->new_owner_name = '';
        $this->new_owner_email = '';
        $this->resetErrorBag();
    }

    public function createTenant(RegisterTenantOwner $registerTenantOwner): void
    {
        $validated = $this->validate([
            'new_business_name' => ['required', 'string', 'max:255'],
            'new_owner_name' => ['required', 'string', 'max:255'],
            'new_owner_email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
        ]);

        $registerTenantOwner(
            $validated['new_business_name'],
            $validated['new_owner_name'],
            $validated['new_owner_email'],
            Hash::make(Str::password(40)),
        );

        Password::sendResetLink(['email' => $validated['new_owner_email']]);

        $this->dispatch('toast', message: __('Tenant created. A password-setup email has been sent to :email.', ['email' => $validated['new_owner_email']]), type: 'success');

        $this->new_business_name = '';
        $this->new_owner_name = '';
        $this->new_owner_email = '';
        unset($this->tenants);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'tenant-form' }))");
    }

    public function saveSubscription(): void
    {
        $validated = $this->validate([
            'plan_id' => ['required', Rule::in($this->planOptions->pluck('id')->all())],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
        ]);

        $tenantId = $this->subscriptionTenantId;

        DB::transaction(function () use ($validated, $tenantId) {
            TenantSubscription::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);

            TenantSubscription::create([
                'tenant_id' => $tenantId,
                'plan_id' => $validated['plan_id'],
                'period_start' => $validated['period_start'],
                'period_end' => $validated['period_end'],
                'status' => 'active',
            ]);
        });

        $this->dispatch('toast', message: __('Subscription updated.'), type: 'success');

        $this->subscriptionTenantId = null;
        unset($this->tenants);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'subscription-form' }))");
    }

    public function activate(int $tenantId): void
    {
        Tenant::findOrFail($tenantId)->update(['status' => 'active']);
        unset($this->tenants);
        $this->dispatch('toast', message: __('Tenant activated.'), type: 'success');
    }

    public function confirmSuspend(int $tenantId): void
    {
        $this->confirmingSuspendId = $tenantId;
    }

    public function suspend(): void
    {
        Tenant::findOrFail($this->confirmingSuspendId)->update(['status' => 'suspended']);
        $this->confirmingSuspendId = null;
        unset($this->tenants);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-suspend-tenant' }))");
        $this->dispatch('toast', message: __('Tenant suspended.'), type: 'success');
    }
}; ?>

<div>
    <x-slot name="header">{{ __('Tenants') }}</x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="flex justify-end">
                <x-primary-button type="button" wire:click="openCreateTenantModal" x-on:click="$dispatch('open-modal', 'tenant-form')">
                    {{ __('Add Tenant') }}
                </x-primary-button>
            </div>

            <div id="tenants-table" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Tenant') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Plan') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Usage') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Period End') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->tenants as $row)
                            @php [$tenant, $subscription] = [$row['tenant'], $row['subscription']]; @endphp
                            <tr wire:key="tenant-{{ $tenant->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    {{ $tenant->name }}
                                    <div class="text-xs text-gray-400 dark:text-gray-500">{{ $tenant->subdomain }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                        @if ($tenant->status === 'active') bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400
                                        @elseif ($tenant->status === 'suspended') bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400
                                        @else bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400
                                        @endif
                                    ">
                                        {{ ucfirst($tenant->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $subscription?->plan?->name ?? __('None') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    {{ __(':branches/:maxBranches branches, :users/:maxUsers users', [
                                        'branches' => $tenant->branches_count,
                                        'maxBranches' => $subscription?->plan?->max_branches ?? '—',
                                        'users' => $tenant->users_count,
                                        'maxUsers' => $subscription?->plan?->max_users ?? '—',
                                    ]) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $subscription?->period_end?->format('d M Y') ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-2">
                                    <button type="button" wire:click="openSubscriptionModal({{ $tenant->id }})" x-on:click="$dispatch('open-modal', 'subscription-form')" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                                        {{ __('Change Plan') }}
                                    </button>
                                    @if ($tenant->status === 'suspended')
                                        <button type="button" wire:click="activate({{ $tenant->id }})" class="text-green-600 dark:text-green-400 hover:text-green-900 dark:hover:text-green-300">
                                            {{ __('Activate') }}
                                        </button>
                                    @else
                                        <button type="button" wire:click="confirmSuspend({{ $tenant->id }})" x-on:click="$dispatch('open-modal', 'confirm-suspend-tenant')" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">
                                            {{ __('Suspend') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No tenants yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-modal name="tenant-form" focusable>
        <form wire:submit="createTenant" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('New Tenant') }}</h2>

            <div class="mt-4">
                <x-input-label for="new_business_name" :value="__('Business Name')" />
                <x-text-input wire:model="new_business_name" id="new_business_name" class="block mt-1 w-full" type="text" autocomplete="organization" />
                <x-input-error :messages="$errors->get('new_business_name')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="new_owner_name" :value="__('Owner Name')" />
                <x-text-input wire:model="new_owner_name" id="new_owner_name" class="block mt-1 w-full" type="text" autocomplete="name" />
                <x-input-error :messages="$errors->get('new_owner_name')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="new_owner_email" :value="__('Owner Email')" />
                <x-text-input wire:model="new_owner_email" id="new_owner_email" class="block mt-1 w-full" type="email" autocomplete="username" />
                <x-input-error :messages="$errors->get('new_owner_email')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Create') }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="subscription-form" focusable>
        <form wire:submit="saveSubscription" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Change Subscription Plan') }}</h2>

            <div class="mt-4">
                <x-input-label for="subscription-plan" :value="__('Plan')" />
                <select wire:model="plan_id" id="subscription-plan" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                    <option value="">{{ __('Select a plan') }}</option>
                    @foreach ($this->planOptions as $plan)
                        <option value="{{ $plan->id }}">{{ $plan->name }} ({{ number_format($plan->price, 2) }})</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('plan_id')" class="mt-2" />
            </div>

            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="subscription-start" :value="__('Period Start')" />
                    <x-text-input wire:model="period_start" id="subscription-start" class="block mt-1 w-full" type="date" />
                    <x-input-error :messages="$errors->get('period_start')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="subscription-end" :value="__('Period End')" />
                    <x-text-input wire:model="period_end" id="subscription-end" class="block mt-1 w-full" type="date" />
                    <x-input-error :messages="$errors->get('period_end')" class="mt-2" />
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-confirm-modal
        name="confirm-suspend-tenant"
        :title="__('Suspend this tenant?')"
        confirm-action="suspend"
        :confirm-label="__('Suspend')"
    >
        {{ __('All of this tenant\'s users will immediately lose access until reactivated.') }}
    </x-confirm-modal>
</div>
