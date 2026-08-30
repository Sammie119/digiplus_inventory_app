<?php

use App\Models\StockTake;
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
        Gate::authorize('viewAny', StockTake::class);
    }

    #[Computed]
    public function stockTakes()
    {
        $actor = Auth::user();

        $query = StockTake::with(['branch', 'financialYear', 'createdBy'])->orderByDesc('id');

        if (! $actor->hasRole('Business Owner')) {
            $query->where('branch_id', $actor->branch_id);
        }

        return $query->paginate(20);
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Stock Takings') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @can('create', \App\Models\StockTake::class)
                <div class="flex justify-end">
                    <a href="{{ route('stock-takings.create') }}" wire:navigate>
                        <x-primary-button type="button">
                            <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                            {{ __('New Stock Take') }}
                        </x-primary-button>
                    </a>
                </div>
            @endcan

            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->stockTakes as $stockTake)
                    <div wire:key="stock-take-card-{{ $stockTake->id }}" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $stockTake->reference }} &middot; {{ $stockTake->branch->name }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ $stockTake->financialYear->name }}</div>
                            </div>
                            <span
                                @class([
                                    'shrink-0 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                                    'bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400' => $stockTake->status === 'approved',
                                    'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400' => $stockTake->status === 'cancelled',
                                    'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400' => $stockTake->status === 'pending_approval',
                                    'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-400' => in_array($stockTake->status, ['draft', 'in_progress']),
                                ])
                            >
                                {{ str($stockTake->status)->headline() }}
                            </span>
                        </div>
                        <div class="mt-3">
                            <a href="{{ route('stock-takings.show', $stockTake) }}" wire:navigate class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View') }}</a>
                        </div>
                    </div>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No stock takings yet.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Reference') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Branch') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Financial Year') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Created By') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->stockTakes as $stockTake)
                            <tr wire:key="stock-take-{{ $stockTake->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $stockTake->reference }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $stockTake->branch->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $stockTake->financialYear->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span
                                        @class([
                                            'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                                            'bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400' => $stockTake->status === 'approved',
                                            'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400' => $stockTake->status === 'cancelled',
                                            'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400' => $stockTake->status === 'pending_approval',
                                            'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-400' => in_array($stockTake->status, ['draft', 'in_progress']),
                                        ])
                                    >
                                        {{ str($stockTake->status)->headline() }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $stockTake->createdBy->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <a href="{{ route('stock-takings.show', $stockTake) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No stock takings yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $this->stockTakes->links() }}
        </div>
    </div>
</div>
