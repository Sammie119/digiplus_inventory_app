<?php

use App\Models\Branch;
use App\Models\Shift;
use App\Models\User;
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
    public ?int $cashier_id = null;

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
    public function cashierOptions()
    {
        $actor = Auth::user();

        $query = Shift::query();

        if ($this->branch_id) {
            $query->where('branch_id', $this->branch_id);
        } elseif (! $actor->hasRole('Business Owner')) {
            $query->where('branch_id', $actor->branch_id);
        }

        $cashierIds = $query->distinct()->pluck('cashier_id');

        return User::whereIn('id', $cashierIds)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function shifts()
    {
        $query = Shift::with(['cashier', 'branch'])
            ->whereDate('opened_at', '>=', $this->from ?: today())
            ->whereDate('opened_at', '<=', $this->to ?: today())
            ->latest('opened_at');

        if ($this->branch_id) {
            $query->where('branch_id', $this->branch_id);
        }

        if ($this->cashier_id) {
            $query->where('cashier_id', $this->cashier_id);
        }

        return $query->get();
    }

    #[Computed]
    public function summary(): array
    {
        $shifts = $this->shifts;
        $closed = $shifts->where('status', 'closed');

        return [
            'count' => $shifts->count(),
            'discrepancies' => $closed->filter(fn ($shift) => (float) $shift->variance !== 0.0)->count(),
            'total_variance' => (float) $closed->sum('variance'),
        ];
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
        return "shift-variance-{$this->from}-to-{$this->to}.{$extension}";
    }

    public function exportCsv()
    {
        Gate::authorize('reports.view');

        $shifts = $this->shifts;
        $summary = $this->summary;
        $from = $this->from;
        $to = $this->to;
        $branchLabel = $this->branchLabel();

        return response()->streamDownload(function () use ($shifts, $summary, $from, $to, $branchLabel) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [__('Shift Variance Report')]);
            fputcsv($handle, [__('Branch'), $branchLabel]);
            fputcsv($handle, [__('Period'), "{$from} to {$to}"]);
            fputcsv($handle, [__('Generated'), now()->toDateTimeString()]);
            fputcsv($handle, []);
            fputcsv($handle, [__('Shifts'), __('Discrepancies'), __('Total Variance')]);
            fputcsv($handle, [$summary['count'], $summary['discrepancies'], number_format($summary['total_variance'], 2, '.', '')]);
            fputcsv($handle, []);
            fputcsv($handle, [__('Cashier'), __('Branch'), __('Opened'), __('Closed'), __('Opening Float'), __('Counted Cash'), __('Expected Cash'), __('Variance'), __('Status')]);

            foreach ($shifts as $shift) {
                fputcsv($handle, [
                    $shift->cashier->name,
                    $shift->branch->name,
                    $shift->opened_at->toDateTimeString(),
                    $shift->closed_at?->toDateTimeString() ?? '',
                    number_format($shift->opening_float, 2, '.', ''),
                    $shift->closing_float_counted !== null ? number_format($shift->closing_float_counted, 2, '.', '') : '',
                    $shift->expected_cash !== null ? number_format($shift->expected_cash, 2, '.', '') : '',
                    $shift->variance !== null ? number_format($shift->variance, 2, '.', '') : '',
                    $shift->status,
                ]);
            }

            fclose($handle);
        }, $this->exportFilename('csv'), ['Content-Type' => 'text/csv']);
    }

    public function exportPdf()
    {
        Gate::authorize('reports.view');

        $pdf = Pdf::loadView('pdf.shift-variance-report', [
            'tenantName' => Auth::user()->tenant->name,
            'branchName' => $this->branchLabel(),
            'from' => $this->from,
            'to' => $this->to,
            'generatedAt' => now()->toDateTimeString(),
            'summary' => $this->summary,
            'shifts' => $this->shifts,
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
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Shift Report') }}</h2>
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
                    <div class="w-48">
                        <x-input-label for="report-branch" :value="__('Branch')" />
                        <select wire:model.live="branch_id" id="report-branch" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                            <option value="">{{ __('All Branches') }}</option>
                            @foreach ($this->branchOptions as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="w-48">
                    <x-input-label for="report-cashier" :value="__('Cashier')" />
                    <select wire:model.live="cashier_id" id="report-cashier" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                        <option value="">{{ __('All Cashiers') }}</option>
                        @foreach ($this->cashierOptions as $cashier)
                            <option value="{{ $cashier->id }}">{{ $cashier->name }}</option>
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

            <div class="grid grid-cols-3 gap-4">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Shifts') }}</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $this->summary['count'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Discrepancies') }}</div>
                    <div class="mt-1 text-xl font-semibold {{ $this->summary['discrepancies'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-gray-100' }}">{{ $this->summary['discrepancies'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Total Variance') }}</div>
                    <div class="mt-1 text-xl font-semibold {{ $this->summary['total_variance'] < 0 ? 'text-red-600 dark:text-red-400' : ($this->summary['total_variance'] > 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-900 dark:text-gray-100') }}">{{ number_format($this->summary['total_variance'], 2) }}</div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Cashier') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Branch') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Opened') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Closed') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Opening Float') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Counted') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Expected') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Variance') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->shifts as $shift)
                            <tr wire:key="shift-{{ $shift->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $shift->cashier->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $shift->branch->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $shift->opened_at->format('d M Y, H:i') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $shift->closed_at?->format('d M Y, H:i') ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($shift->opening_float, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $shift->closing_float_counted !== null ? number_format($shift->closing_float_counted, 2) : '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $shift->expected_cash !== null ? number_format($shift->expected_cash, 2) : '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm {{ $shift->variance !== null && (float) $shift->variance < 0 ? 'text-red-600 dark:text-red-400' : ($shift->variance !== null && (float) $shift->variance > 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-500 dark:text-gray-400') }}">
                                    {{ $shift->variance !== null ? number_format($shift->variance, 2) : '—' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $shift->status === 'open' ? 'bg-blue-50 dark:bg-blue-900/40 text-blue-700 dark:text-blue-400' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' }}">
                                        {{ ucfirst($shift->status) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No shifts in this range.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
