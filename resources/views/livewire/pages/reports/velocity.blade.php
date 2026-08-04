<?php

use App\Models\Branch;
use App\Models\Item;
use App\Reports\ItemVelocityData;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public const WINDOW_OPTIONS = [30, 60, 90];

    public ?int $branch_id = null;
    public int $window_days = 30;

    public function mount(): void
    {
        Gate::authorize('reports.view');

        $actor = Auth::user();
        $this->branch_id = $actor->hasRole('Business Owner') ? null : $actor->branch_id;
    }

    #[Computed]
    public function branchOptions()
    {
        $actor = Auth::user();

        if ($actor->hasRole('Business Owner')) {
            return Branch::orderBy('name')->get();
        }

        return Branch::where('id', $actor->branch_id)->get();
    }

    private function windowDates(): array
    {
        return [now()->subDays($this->window_days)->toDateString(), now()->toDateString()];
    }

    #[Computed]
    public function velocity(): Collection
    {
        [$from, $to] = $this->windowDates();

        return ItemVelocityData::build(Auth::user()->tenant_id, $this->branch_id, $from, $to);
    }

    #[Computed]
    public function stockRows(): Collection
    {
        $branchId = $this->branch_id;

        $items = Item::with(['baseUnit', 'stockLevels' => function ($query) use ($branchId) {
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        }])->orderBy('sku')->get();

        $velocity = $this->velocity;

        return $items->map(function (Item $item) use ($branchId, $velocity) {
            $onHand = (float) $item->stockLevels->sum('quantity_on_hand');
            $reorderLevel = $branchId ? $item->reorderLevelForBranch($branchId) : $item->reorder_level;
            $stats = $velocity->get($item->id);

            return [
                'item' => $item,
                'on_hand' => $onHand,
                'reorder_level' => $reorderLevel,
                'units_sold' => (float) ($stats->units_sold ?? 0),
                'revenue' => (float) ($stats->revenue ?? 0),
                'last_sold_at' => $stats?->last_sold_at ? Carbon::parse($stats->last_sold_at) : null,
            ];
        });
    }

    #[Computed]
    public function fastMovers(): Collection
    {
        return $this->stockRows
            ->filter(fn ($row) => $row['units_sold'] > 0)
            ->sortByDesc('units_sold')
            ->take(20)
            ->values();
    }

    #[Computed]
    public function slowMoving(): Collection
    {
        return $this->stockRows
            ->filter(fn ($row) => $row['on_hand'] > 0 && $row['units_sold'] == 0)
            ->sortBy(fn ($row) => $row['last_sold_at']?->toDateString() ?? '0000-00-00')
            ->values();
    }

    #[Computed]
    public function abcAnalysis(): Collection
    {
        $rows = $this->stockRows->filter(fn ($row) => $row['revenue'] > 0)->sortByDesc('revenue')->values();
        $totalRevenue = (float) $rows->sum('revenue');

        if ($totalRevenue <= 0) {
            return collect();
        }

        $cumulative = 0.0;

        return $rows->map(function ($row) use (&$cumulative, $totalRevenue) {
            // Classify by where the cumulative percentage stood *before* this
            // item (not after) — otherwise a single dominant item can jump
            // straight past both thresholds in one step and land in C, which
            // is backwards: the item driving that jump is exactly an "A".
            $cumulativePctBefore = $cumulative / $totalRevenue * 100;

            $cumulative += $row['revenue'];
            $row['cumulative_pct'] = $cumulative / $totalRevenue * 100;
            $row['class'] = match (true) {
                $cumulativePctBefore < 80 => 'A',
                $cumulativePctBefore < 95 => 'B',
                default => 'C',
            };

            return $row;
        });
    }

    #[Computed]
    public function reorderSuggestions(): Collection
    {
        return $this->stockRows
            ->filter(fn ($row) => $row['on_hand'] <= $row['reorder_level'])
            ->map(function ($row) {
                $row['daily_velocity'] = $row['units_sold'] / max($this->window_days, 1);
                $row['suggested_qty'] = $row['units_sold'] > 0
                    ? max(round($row['units_sold']) - $row['on_hand'], 0)
                    : max($row['reorder_level'] - $row['on_hand'], 0);

                return $row;
            })
            ->sortByDesc('suggested_qty')
            ->values();
    }

    private function branchLabel(): string
    {
        if (! $this->branch_id) {
            return __('All Branches');
        }

        return $this->branchOptions->firstWhere('id', $this->branch_id)?->name ?? __('All Branches');
    }

    private function exportFilename(string $extension): string
    {
        return "item-velocity-report-{$this->window_days}-days.{$extension}";
    }

    public function exportCsv()
    {
        Gate::authorize('reports.view');

        $fastMovers = $this->fastMovers;
        $slowMoving = $this->slowMoving;
        $abcAnalysis = $this->abcAnalysis;
        $reorderSuggestions = $this->reorderSuggestions;
        $windowDays = $this->window_days;
        $branchLabel = $this->branchLabel();

        return response()->streamDownload(function () use ($fastMovers, $slowMoving, $abcAnalysis, $reorderSuggestions, $windowDays, $branchLabel) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [__('Item Velocity Report')]);
            fputcsv($handle, [__('Branch'), $branchLabel]);
            fputcsv($handle, [__('Window'), __(':days days', ['days' => $windowDays])]);
            fputcsv($handle, [__('Generated'), now()->toDateTimeString()]);

            fputcsv($handle, []);
            fputcsv($handle, [__('Fast Movers')]);
            fputcsv($handle, [__('SKU'), __('Units Sold'), __('Revenue'), __('Daily Velocity')]);
            foreach ($fastMovers as $row) {
                fputcsv($handle, [$row['item']->sku, $row['units_sold'], number_format($row['revenue'], 2, '.', ''), round($row['units_sold'] / max($windowDays, 1), 2)]);
            }

            fputcsv($handle, []);
            fputcsv($handle, [__('Slow/Dead Stock')]);
            fputcsv($handle, [__('SKU'), __('On Hand'), __('Last Sold')]);
            foreach ($slowMoving as $row) {
                fputcsv($handle, [$row['item']->sku, $row['on_hand'], $row['last_sold_at']?->toDateString() ?? __('Never')]);
            }

            fputcsv($handle, []);
            fputcsv($handle, [__('ABC Analysis')]);
            fputcsv($handle, [__('SKU'), __('Revenue'), __('Cumulative %'), __('Class')]);
            foreach ($abcAnalysis as $row) {
                fputcsv($handle, [$row['item']->sku, number_format($row['revenue'], 2, '.', ''), round($row['cumulative_pct'], 1), $row['class']]);
            }

            fputcsv($handle, []);
            fputcsv($handle, [__('Reorder Suggestions')]);
            fputcsv($handle, [__('SKU'), __('On Hand'), __('Reorder Level'), __('Suggested Qty')]);
            foreach ($reorderSuggestions as $row) {
                fputcsv($handle, [$row['item']->sku, $row['on_hand'], $row['reorder_level'], $row['suggested_qty']]);
            }

            fclose($handle);
        }, $this->exportFilename('csv'), ['Content-Type' => 'text/csv']);
    }

    public function exportPdf()
    {
        Gate::authorize('reports.view');

        $pdf = Pdf::loadView('pdf.item-velocity-report', [
            'tenantName' => Auth::user()->tenant->name,
            'branchName' => $this->branchLabel(),
            'windowDays' => $this->window_days,
            'generatedAt' => now()->toDateTimeString(),
            'fastMovers' => $this->fastMovers,
            'slowMoving' => $this->slowMoving,
            'abcAnalysis' => $this->abcAnalysis,
            'reorderSuggestions' => $this->reorderSuggestions,
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->exportFilename('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }
}; ?>

<div x-data="{ tab: 'fast' }">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Item Velocity') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 flex flex-wrap items-end gap-4">
                @if ($this->branchOptions->count() > 1)
                    <div class="w-56">
                        <x-input-label for="velocity-branch" :value="__('Branch')" />
                        <select wire:model.live="branch_id" id="velocity-branch" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                            <option value="">{{ __('All Branches') }}</option>
                            @foreach ($this->branchOptions as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="w-40">
                    <x-input-label for="velocity-window" :value="__('Window')" />
                    <select wire:model.live="window_days" id="velocity-window" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                        @foreach (self::WINDOW_OPTIONS as $days)
                            <option value="{{ $days }}">{{ __(':days days', ['days' => $days]) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-2 ml-auto">
                    <x-secondary-button type="button" wire:click="exportCsv">
                        {{ __('Export CSV') }}
                    </x-secondary-button>
                    <x-secondary-button type="button" wire:click="exportPdf">
                        {{ __('Export PDF') }}
                    </x-secondary-button>
                </div>
            </div>

            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav class="-mb-px flex gap-6">
                    <button type="button" x-on:click="tab = 'fast'" :class="tab === 'fast' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm">{{ __('Fast Movers') }}</button>
                    <button type="button" x-on:click="tab = 'slow'" :class="tab === 'slow' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm">{{ __('Slow/Dead Stock') }}</button>
                    <button type="button" x-on:click="tab = 'abc'" :class="tab === 'abc' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm">{{ __('ABC Analysis') }}</button>
                    <button type="button" x-on:click="tab = 'reorder'" :class="tab === 'reorder' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm">{{ __('Reorder Suggestions') }}</button>
                </nav>
            </div>

            <!-- Fast Movers -->
            <div x-show="tab === 'fast'" style="display: none;">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('SKU') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Units Sold') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Revenue') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Daily Velocity') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($this->fastMovers as $row)
                                <tr wire:key="fast-{{ $row['item']->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $row['item']->sku }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ rtrim(rtrim(number_format($row['units_sold'], 3), '0'), '.') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($row['revenue'], 2) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($row['units_sold'] / max($window_days, 1), 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No sales in this window.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Slow/Dead Stock -->
            <div x-show="tab === 'slow'" style="display: none;">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('SKU') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('On Hand') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Last Sold') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($this->slowMoving as $row)
                                <tr wire:key="slow-{{ $row['item']->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $row['item']->sku }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ rtrim(rtrim(number_format($row['on_hand'], 3), '0'), '.') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400">
                                            {{ $row['last_sold_at']?->format('d M Y') ?? __('Never sold') }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No slow-moving items in this window.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ABC Analysis -->
            <div x-show="tab === 'abc'" style="display: none;">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('SKU') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Revenue') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Cumulative %') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Class') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($this->abcAnalysis as $row)
                                <tr wire:key="abc-{{ $row['item']->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $row['item']->sku }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($row['revenue'], 2) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($row['cumulative_pct'], 1) }}%</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                            @if ($row['class'] === 'A') bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400
                                            @elseif ($row['class'] === 'B') bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400
                                            @else bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300
                                            @endif
                                        ">{{ $row['class'] }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No sales in this window.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Reorder Suggestions -->
            <div x-show="tab === 'reorder'" style="display: none;">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('SKU') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('On Hand') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Reorder Level') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Suggested Qty') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($this->reorderSuggestions as $row)
                                <tr wire:key="reorder-{{ $row['item']->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $row['item']->sku }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ rtrim(rtrim(number_format($row['on_hand'], 3), '0'), '.') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $row['reorder_level'] }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">{{ rtrim(rtrim(number_format($row['suggested_qty'], 3), '0'), '.') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No items are under their reorder level.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
