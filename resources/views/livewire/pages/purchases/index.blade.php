<?php

use App\Models\Purchase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public function mount(): void
    {
        Gate::authorize('viewAny', Purchase::class);
    }

    #[Computed]
    public function purchases()
    {
        $actor = Auth::user();

        $query = Purchase::with(['branch', 'supplier'])->orderByDesc('id');

        if (! $actor->hasRole('Business Owner')) {
            $query->where('branch_id', $actor->branch_id);
        }

        return $query->paginate(20);
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Purchases') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @can('create', \App\Models\Purchase::class)
                <div class="flex justify-end">
                    <a
                        href="{{ route('purchases.create') }}"
                        wire:navigate
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 dark:bg-indigo-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 dark:hover:bg-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 transition ease-in-out duration-150"
                    >
                        <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                        {{ __('New Purchase') }}
                    </a>
                </div>
            @endcan

            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->purchases as $purchase)
                    <a
                        wire:key="purchase-card-{{ $purchase->id }}"
                        href="{{ route('purchases.show', $purchase) }}"
                        wire:navigate
                        class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $purchase->purchase_reference ?? '#'.$purchase->id }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ $purchase->supplier->name }} &middot; {{ $purchase->branch->name }}</div>
                            </div>
                            <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $purchase->status === 'received' ? 'bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' }}">
                                {{ ucfirst($purchase->status) }}
                            </span>
                        </div>
                        <div class="mt-3 text-sm text-gray-900 dark:text-gray-100">{{ number_format($purchase->total_cost, 2) }}</div>
                    </a>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No purchases yet.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Reference') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Branch') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Supplier') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Total') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Date') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->purchases as $purchase)
                            <tr wire:key="purchase-{{ $purchase->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <a href="{{ route('purchases.show', $purchase) }}" wire:navigate class="hover:text-indigo-600 dark:hover:text-indigo-400">
                                        {{ $purchase->purchase_reference ?? '#'.$purchase->id }}
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $purchase->branch->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $purchase->supplier->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $purchase->status === 'received' ? 'bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' }}">
                                        {{ ucfirst($purchase->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($purchase->total_cost, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $purchase->created_at->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No purchases yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $this->purchases->links() }}
        </div>
    </div>
</div>
