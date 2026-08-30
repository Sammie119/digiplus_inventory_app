<?php

use App\Models\Branch;
use App\Models\FinancialYear;
use App\Reports\ProfitLossReportData;
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
    public ?int $financial_year_id = null;

    public function mount(): void
    {
        Gate::authorize('reports.view');

        $actor = Auth::user();
        $this->branch_id = $actor->hasRole('Business Owner') ? null : $actor->branch_id;

        $openYear = FinancialYear::where('tenant_id', $actor->tenant_id)->where('status', 'open')->first();

        if ($openYear) {
            $this->financial_year_id = $openYear->id;
            $this->from = $openYear->start_date->toDateString();
            $this->to = $openYear->end_date->toDateString();
        } else {
            $this->from = now()->startOfMonth()->toDateString();
            $this->to = now()->endOfMonth()->toDateString();
        }
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
    public function financialYearOptions()
    {
        return FinancialYear::orderByDesc('start_date')->get();
    }

    public function updatedFinancialYearId(): void
    {
        $year = $this->financialYearOptions->firstWhere('id', $this->financial_year_id);

        if ($year) {
            $this->from = $year->start_date->toDateString();
            $this->to = $year->end_date->toDateString();
        }
    }

    #[Computed]
    public function reportData(): array
    {
        return ProfitLossReportData::build(Auth::user()->tenant_id, $this->branch_id, $this->from ?: today()->toDateString(), $this->to ?: today()->toDateString());
    }

    #[Computed]
    public function summary(): array
    {
        return $this->reportData['summary'];
    }

    #[Computed]
    public function dailyBreakdown(): array
    {
        return $this->reportData['dailyBreakdown'];
    }

    #[Computed]
    public function categoryBreakdown(): array
    {
        return $this->reportData['categoryBreakdown'];
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
        return "profit-loss-report-{$this->from}-to-{$this->to}.{$extension}";
    }

    public function exportCsv()
    {
        Gate::authorize('reports.view');

        $summary = $this->summary;
        $dailyRows = $this->dailyBreakdown;
        $categoryRows = $this->categoryBreakdown;
        $from = $this->from;
        $to = $this->to;
        $branchLabel = $this->branchLabel();

        return response()->streamDownload(function () use ($summary, $dailyRows, $categoryRows, $from, $to, $branchLabel) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [__('Profit & Loss Report')]);
            fputcsv($handle, [__('Branch'), $branchLabel]);
            fputcsv($handle, [__('Period'), "{$from} to {$to}"]);
            fputcsv($handle, [__('Generated'), now()->toDateTimeString()]);
            fputcsv($handle, []);
            fputcsv($handle, [__('Net Revenue'), __('Returns'), __('Net COGS'), __('Shrinkage'), __('Gross Profit'), __('Margin %')]);
            fputcsv($handle, [
                number_format($summary['net_revenue'], 2, '.', ''),
                number_format($summary['returns_revenue'], 2, '.', ''),
                number_format($summary['net_cogs'], 2, '.', ''),
                number_format($summary['shrinkage'], 2, '.', ''),
                number_format($summary['net_gross_profit'], 2, '.', ''),
                number_format($summary['gross_margin_pct'], 1, '.', ''),
            ]);
            fputcsv($handle, []);
            fputcsv($handle, [__('Date'), __('Sales'), __('Revenue'), __('COGS'), __('Shrinkage'), __('Gross Profit')]);

            foreach ($dailyRows as $day) {
                fputcsv($handle, [
                    $day['date'],
                    $day['count'],
                    number_format($day['revenue'], 2, '.', ''),
                    number_format($day['cogs'], 2, '.', ''),
                    number_format($day['shrinkage'], 2, '.', ''),
                    number_format($day['gross_profit'], 2, '.', ''),
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, [__('Category'), __('Revenue'), __('COGS'), __('Gross Profit')]);

            foreach ($categoryRows as $row) {
                fputcsv($handle, [
                    $row['category'],
                    number_format($row['revenue'], 2, '.', ''),
                    number_format($row['cogs'], 2, '.', ''),
                    number_format($row['gross_profit'], 2, '.', ''),
                ]);
            }

            fclose($handle);
        }, $this->exportFilename('csv'), ['Content-Type' => 'text/csv']);
    }

    public function exportPdf()
    {
        Gate::authorize('reports.view');

        $pdf = Pdf::loadView('pdf.profit-loss-report', [
            'tenantName' => Auth::user()->tenant->name,
            'branchName' => $this->branchLabel(),
            'from' => $this->from,
            'to' => $this->to,
            'generatedAt' => now()->toDateTimeString(),
            'summary' => $this->summary,
            'dailyBreakdown' => $this->dailyBreakdown,
            'categoryBreakdown' => $this->categoryBreakdown,
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
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Profit & Loss Report') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 flex flex-wrap items-end gap-4">
                @if ($this->financialYearOptions->isNotEmpty())
                    <div class="w-56">
                        <x-input-label for="report-financial-year" :value="__('Financial Year')" />
                        <select wire:model.live="financial_year_id" id="report-financial-year" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                            <option value="">{{ __('Custom range') }}</option>
                            @foreach ($this->financialYearOptions as $year)
                                <option value="{{ $year->id }}">{{ $year->name }} ({{ ucfirst($year->status) }})</option>
                            @endforeach
                        </select>
                    </div>
                @endif

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

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Net Revenue') }}</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->summary['net_revenue'], 2) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Returns') }}</div>
                    <div class="mt-1 text-xl font-semibold text-red-600 dark:text-red-400">-{{ number_format($this->summary['returns_revenue'], 2) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Net COGS') }}</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->summary['net_cogs'], 2) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Shrinkage') }}</div>
                    <div class="mt-1 text-xl font-semibold text-red-600 dark:text-red-400">-{{ number_format($this->summary['shrinkage'], 2) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Gross Profit') }}</div>
                    <div class="mt-1 text-xl font-semibold text-green-600 dark:text-green-400">{{ number_format($this->summary['net_gross_profit'], 2) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Margin %') }}</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->summary['gross_margin_pct'], 1) }}%</div>
                </div>
            </div>

            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('Gross profit only — operating expenses (rent, salaries, utilities, etc.) aren\'t tracked in DigiPlus and are not reflected here.') }}
            </p>

            <div>
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Daily Breakdown') }}</h3>
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Date') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Sales') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Revenue') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('COGS') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Shrinkage') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Gross Profit') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($this->dailyBreakdown as $day)
                                <tr wire:key="day-{{ $day['date'] }}">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d M Y') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $day['count'] }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($day['revenue'], 2) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($day['cogs'], 2) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($day['shrinkage'], 2) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($day['gross_profit'], 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No sales in this range.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('By Category') }}</h3>
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Category') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Revenue') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('COGS') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Gross Profit') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($this->categoryBreakdown as $row)
                                <tr wire:key="category-{{ $row['category'] }}">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $row['category'] }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($row['revenue'], 2) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($row['cogs'], 2) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($row['gross_profit'], 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No sales in this range.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
