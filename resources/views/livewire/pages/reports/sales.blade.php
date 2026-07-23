<?php

use App\Models\Branch;
use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $from = '';
    public string $to = '';
    public ?int $branch_id = null;

    public function mount(): void
    {
        Gate::authorize('reports.view');

        $actor = Auth::user();

        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->endOfMonth()->toDateString();
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

    #[Computed]
    public function sales()
    {
        $query = Sale::with('items')
            ->where('status', 'completed')
            ->whereDate('created_at', '>=', $this->from ?: today())
            ->whereDate('created_at', '<=', $this->to ?: today());

        if ($this->branch_id) {
            $query->where('branch_id', $this->branch_id);
        }

        return $query->get();
    }

    #[Computed]
    public function summary(): array
    {
        $sales = $this->sales;

        $revenue = (float) $sales->sum('grand_total');
        $cost = (float) $sales->sum(fn ($sale) => $sale->items->sum(fn ($line) => $line->cost_at_sale * $line->quantity));
        $margin = $revenue - $cost;

        return [
            'count' => $sales->count(),
            'revenue' => $revenue,
            'cost' => $cost,
            'margin' => $margin,
            'margin_pct' => $revenue > 0 ? ($margin / $revenue) * 100 : 0,
        ];
    }

    #[Computed]
    public function dailyBreakdown(): array
    {
        return $this->sales
            ->groupBy(fn ($sale) => $sale->created_at->format('Y-m-d'))
            ->map(function ($daySales, $date) {
                $revenue = (float) $daySales->sum('grand_total');
                $cost = (float) $daySales->sum(fn ($sale) => $sale->items->sum(fn ($line) => $line->cost_at_sale * $line->quantity));

                return [
                    'date' => $date,
                    'count' => $daySales->count(),
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'margin' => $revenue - $cost,
                ];
            })
            ->sortKeysDesc()
            ->values()
            ->all();
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
        return "sales-report-{$this->from}-to-{$this->to}.{$extension}";
    }

    public function exportCsv()
    {
        Gate::authorize('reports.view');

        $summary = $this->summary;
        $rows = $this->dailyBreakdown;
        $from = $this->from;
        $to = $this->to;
        $branchLabel = $this->branchLabel();

        return response()->streamDownload(function () use ($summary, $rows, $from, $to, $branchLabel) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [__('Sales Report')]);
            fputcsv($handle, [__('Branch'), $branchLabel]);
            fputcsv($handle, [__('Period'), "{$from} to {$to}"]);
            fputcsv($handle, [__('Generated'), now()->toDateTimeString()]);
            fputcsv($handle, []);
            fputcsv($handle, [__('Sales'), __('Revenue'), __('Cost'), __('Margin'), __('Margin %')]);
            fputcsv($handle, [
                $summary['count'],
                number_format($summary['revenue'], 2, '.', ''),
                number_format($summary['cost'], 2, '.', ''),
                number_format($summary['margin'], 2, '.', ''),
                number_format($summary['margin_pct'], 1, '.', ''),
            ]);
            fputcsv($handle, []);
            fputcsv($handle, [__('Date'), __('Sales'), __('Revenue'), __('Cost'), __('Margin')]);

            foreach ($rows as $day) {
                fputcsv($handle, [
                    $day['date'],
                    $day['count'],
                    number_format($day['revenue'], 2, '.', ''),
                    number_format($day['cost'], 2, '.', ''),
                    number_format($day['margin'], 2, '.', ''),
                ]);
            }

            fclose($handle);
        }, $this->exportFilename('csv'), ['Content-Type' => 'text/csv']);
    }

    public function exportPdf()
    {
        Gate::authorize('reports.view');

        $pdf = Pdf::loadView('pdf.sales-report', [
            'tenantName' => Auth::user()->tenant->name,
            'branchName' => $this->branchLabel(),
            'from' => $this->from,
            'to' => $this->to,
            'generatedAt' => now()->toDateTimeString(),
            'summary' => $this->summary,
            'dailyBreakdown' => $this->dailyBreakdown,
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->exportFilename('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Sales Report') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 flex flex-wrap items-end gap-4">
                <div>
                    <x-input-label for="report-from" :value="__('From')" />
                    <x-text-input wire:model.live="from" id="report-from" class="block mt-1" type="date" />
                </div>
                <div>
                    <x-input-label for="report-to" :value="__('To')" />
                    <x-text-input wire:model.live="to" id="report-to" class="block mt-1" type="date" />
                </div>
                @if ($this->branchOptions->count() > 1)
                    <div class="w-56">
                        <x-input-label for="report-branch" :value="__('Branch')" />
                        <select wire:model.live="branch_id" id="report-branch" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                            <option value="">{{ __('All Branches') }}</option>
                            @foreach ($this->branchOptions as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="flex gap-2 ml-auto">
                    <x-secondary-button type="button" wire:click="exportCsv">
                        {{ __('Export CSV') }}
                    </x-secondary-button>
                    <x-secondary-button type="button" wire:click="exportPdf">
                        {{ __('Export PDF') }}
                    </x-secondary-button>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Sales') }}</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $this->summary['count'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Revenue') }}</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->summary['revenue'], 2) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Cost') }}</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->summary['cost'], 2) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Margin') }}</div>
                    <div class="mt-1 text-xl font-semibold text-green-600 dark:text-green-400">{{ number_format($this->summary['margin'], 2) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Margin %') }}</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->summary['margin_pct'], 1) }}%</div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Date') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Sales') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Revenue') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Cost') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Margin') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->dailyBreakdown as $day)
                            <tr wire:key="day-{{ $day['date'] }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d M Y') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $day['count'] }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($day['revenue'], 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($day['cost'], 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($day['margin'], 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No sales in this range.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
