<?php

use App\Models\Item;
use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Computed]
    public function canViewReports(): bool
    {
        return Auth::user()->can('reports.view');
    }

    #[Computed]
    public function branchScopeId(): ?int
    {
        $actor = Auth::user();

        return $actor->hasRole('Business Owner') ? null : $actor->branch_id;
    }

    #[Computed]
    public function openShift(): ?Shift
    {
        $actor = Auth::user();

        if (! $actor->can('sales.create')) {
            return null;
        }

        return Shift::where('cashier_id', $actor->id)
            ->where('branch_id', $actor->branch_id)
            ->where('status', 'open')
            ->first();
    }

    #[Computed]
    public function todaySales(): array
    {
        $query = Sale::where('status', 'completed')->whereDate('created_at', today());

        if ($this->branchScopeId) {
            $query->where('branch_id', $this->branchScopeId);
        }

        return [
            'count' => (clone $query)->count(),
            'revenue' => (float) (clone $query)->sum('grand_total'),
        ];
    }

    #[Computed]
    public function monthRevenue(): float
    {
        $query = Sale::where('status', 'completed')
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);

        if ($this->branchScopeId) {
            $query->where('branch_id', $this->branchScopeId);
        }

        return (float) $query->sum('grand_total');
    }

    #[Computed]
    public function stockHealth(): array
    {
        $branchId = $this->branchScopeId;

        $items = Item::with(['stockLevels' => function ($query) use ($branchId) {
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        }])->get();

        $low = 0;
        $out = 0;

        foreach ($items as $item) {
            $onHand = (float) $item->stockLevels->sum('quantity_on_hand');
            $reorderLevel = $branchId ? $item->reorderLevelForBranch($branchId) : $item->reorder_level;

            if ($onHand <= 0) {
                $out++;
            } elseif ($onHand <= $reorderLevel) {
                $low++;
            }
        }

        return ['low' => $low, 'out' => $out];
    }

    #[Computed]
    public function openShiftsCount(): int
    {
        $query = Shift::where('status', 'open');

        if ($this->branchScopeId) {
            $query->where('branch_id', $this->branchScopeId);
        }

        return $query->count();
    }

    #[Computed]
    public function salesTrend(): array
    {
        $branchId = $this->branchScopeId;

        $query = Sale::where('status', 'completed')->where('created_at', '>=', now()->subDays(6)->startOfDay());

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $sales = $query->get(['created_at', 'grand_total']);

        return collect(range(0, 6))->map(function ($i) use ($sales) {
            $date = now()->subDays(6 - $i);
            $revenue = $sales->filter(fn ($sale) => $sale->created_at->isSameDay($date))->sum('grand_total');

            return ['label' => $date->format('D'), 'revenue' => (float) $revenue];
        })->all();
    }

    #[Computed]
    public function topCategories(): array
    {
        $branchId = $this->branchScopeId;

        $query = Sale::with('items.item.product.category')
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30)->startOfDay());

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $totals = [];

        foreach ($query->get() as $sale) {
            foreach ($sale->items as $line) {
                $name = $line->item->product?->category?->name ?? __('Uncategorized');
                $totals[$name] = ($totals[$name] ?? 0) + (float) $line->line_total;
            }
        }

        arsort($totals);

        return collect($totals)
            ->take(5)
            ->map(fn ($total, $name) => ['name' => $name, 'total' => $total])
            ->values()
            ->all();
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Dashboard') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                <p class="text-gray-700 dark:text-gray-300">{{ __('Welcome back, :name.', ['name' => Auth::user()->name]) }}</p>

                @can('sales.create')
                    <div class="mt-3">
                        @if ($this->openShift)
                            <p class="text-sm text-green-600 dark:text-green-400">{{ __('You have an open shift.') }}</p>
                        @else
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('You have no open shift right now.') }}</p>
                        @endif
                        <a href="{{ route('pos.index') }}" wire:navigate class="inline-flex items-center mt-2 text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                            <x-icon.shopping-cart class="h-4 w-4 mr-1.5" />
                            {{ __('Go to Point of Sale') }}
                        </a>
                    </div>
                @endcan
            </div>

            @if ($this->canViewReports)
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __("Today's Sales") }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->todaySales['revenue'], 2) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ __(':count transaction(s)', ['count' => $this->todaySales['count']]) }}</div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('This Month') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->monthRevenue, 2) }}</div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Low Stock') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-amber-600 dark:text-amber-400">{{ $this->stockHealth['low'] }}</div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Out of Stock') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-red-600 dark:text-red-400">{{ $this->stockHealth['out'] }}</div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Open Shifts') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $this->openShiftsCount }}</div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">{{ __('Sales Trend (Last 7 Days)') }}</h3>
                        @php $maxRevenue = max(1, collect($this->salesTrend)->max('revenue')); @endphp
                        <div class="flex items-end justify-between gap-2 h-40">
                            @foreach ($this->salesTrend as $day)
                                <div class="flex-1 flex flex-col items-center justify-end gap-1">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($day['revenue'], 0) }}</span>
                                    <div
                                        class="w-full bg-indigo-500 dark:bg-indigo-400 rounded-t"
                                        style="height: {{ max(2, ($day['revenue'] / $maxRevenue) * 100) }}%"
                                    ></div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $day['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">{{ __('Top Categories (Last 30 Days)') }}</h3>
                        @if (empty($this->topCategories))
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No sales in this period yet.') }}</p>
                        @else
                            @php $maxCategoryTotal = max(1, collect($this->topCategories)->max('total')); @endphp
                            <div class="space-y-3">
                                @foreach ($this->topCategories as $category)
                                    <div>
                                        <div class="flex justify-between text-sm text-gray-700 dark:text-gray-300 mb-1">
                                            <span>{{ $category['name'] }}</span>
                                            <span>{{ number_format($category['total'], 2) }}</span>
                                        </div>
                                        <div class="w-full bg-gray-100 dark:bg-gray-700 rounded h-2">
                                            <div class="bg-indigo-500 dark:bg-indigo-400 h-2 rounded" style="width: {{ ($category['total'] / $maxCategoryTotal) * 100 }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
