<?php

use App\Models\StockTransfer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', StockTransfer::class);
    }

    #[Computed]
    public function transfers()
    {
        $actor = Auth::user();

        $query = StockTransfer::with(['fromBranch', 'toBranch', 'items'])->latest();

        if (! $actor->hasRole('Business Owner')) {
            $query->where(function ($q) use ($actor) {
                $q->where('from_branch_id', $actor->branch_id)
                    ->orWhere('to_branch_id', $actor->branch_id);
            });
        }

        return $query->get();
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Stock Transfers') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @can('create', \App\Models\StockTransfer::class)
                <div class="flex justify-end">
                    <a
                        href="{{ route('stock-transfers.create') }}"
                        wire:navigate
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 dark:bg-indigo-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 dark:hover:bg-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 transition ease-in-out duration-150"
                    >
                        <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                        {{ __('New Transfer') }}
                    </a>
                </div>
            @endcan

            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->transfers as $transfer)
                    <a
                        wire:key="transfer-card-{{ $transfer->id }}"
                        href="{{ route('stock-transfers.show', $transfer) }}"
                        wire:navigate
                        class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $transfer->fromBranch->name }} &rarr; {{ $transfer->toBranch->name }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __(':count item(s)', ['count' => $transfer->items->count()]) }}</div>
                            </div>
                            <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                @if ($transfer->status === 'received') bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400
                                @elseif ($transfer->status === 'in_transit') bg-blue-50 dark:bg-blue-900/40 text-blue-700 dark:text-blue-400
                                @elseif ($transfer->status === 'cancelled') bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400
                                @else bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400
                                @endif
                            ">
                                {{ ucfirst(str_replace('_', ' ', $transfer->status)) }}
                            </span>
                        </div>
                    </a>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No stock transfers yet.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('From') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('To') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Items') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Date') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->transfers as $transfer)
                            <tr wire:key="transfer-{{ $transfer->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <a href="{{ route('stock-transfers.show', $transfer) }}" wire:navigate class="hover:text-indigo-600 dark:hover:text-indigo-400">
                                        {{ $transfer->fromBranch->name }}
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $transfer->toBranch->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $transfer->items->count() }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                        @if ($transfer->status === 'received') bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400
                                        @elseif ($transfer->status === 'in_transit') bg-blue-50 dark:bg-blue-900/40 text-blue-700 dark:text-blue-400
                                        @elseif ($transfer->status === 'cancelled') bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400
                                        @else bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400
                                        @endif
                                    ">
                                        {{ ucfirst(str_replace('_', ' ', $transfer->status)) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $transfer->created_at->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No stock transfers yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
