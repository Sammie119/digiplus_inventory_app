<?php

use App\Models\Tenant;
use App\Models\TenantSubscription;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.super-admin')] class extends Component
{
    #[Computed]
    public function tenantCounts(): array
    {
        return [
            'total' => Tenant::count(),
            'trial' => Tenant::where('status', 'trial')->count(),
            'active' => Tenant::where('status', 'active')->count(),
            'suspended' => Tenant::where('status', 'suspended')->count(),
        ];
    }

    #[Computed]
    public function expiringSoon()
    {
        return TenantSubscription::withoutTenantScope()
            ->with(['tenant', 'plan'])
            ->where('status', 'active')
            ->whereBetween('period_end', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->orderBy('period_end')
            ->get();
    }
}; ?>

<div>
    <x-slot name="header">{{ __('Dashboard') }}</x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Tenants') }}</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $this->tenantCounts['total'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Trial') }}</div>
                    <div class="mt-1 text-xl font-semibold text-amber-600 dark:text-amber-400">{{ $this->tenantCounts['trial'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Active') }}</div>
                    <div class="mt-1 text-xl font-semibold text-green-600 dark:text-green-400">{{ $this->tenantCounts['active'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Suspended') }}</div>
                    <div class="mt-1 text-xl font-semibold text-red-600 dark:text-red-400">{{ $this->tenantCounts['suspended'] }}</div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Subscriptions Expiring in the Next 7 Days') }}</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Tenant') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Plan') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Period End') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->expiringSoon as $subscription)
                            <tr wire:key="expiring-{{ $subscription->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $subscription->tenant->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $subscription->plan->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $subscription->period_end->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing expiring soon.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
