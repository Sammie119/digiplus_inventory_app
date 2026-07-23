<?php

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Item;
use App\Models\StockMovement;
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
    public function stockRows()
    {
        $branchId = $this->branch_id;

        $items = Item::with(['baseUnit', 'stockLevels' => function ($query) use ($branchId) {
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        }])->orderBy('sku')->get();

        return $items->map(function (Item $item) use ($branchId) {
            $onHand = (float) $item->stockLevels->sum('quantity_on_hand');
            $reorderLevel = $branchId ? $item->reorderLevelForBranch($branchId) : $item->reorder_level;

            $status = match (true) {
                $onHand <= 0 => 'out',
                $onHand <= $reorderLevel => 'low',
                default => 'ok',
            };

            return [
                'item' => $item,
                'on_hand' => $onHand,
                'reorder_level' => $reorderLevel,
                'status' => $status,
                'value' => $onHand * (float) $item->cost_price,
            ];
        });
    }

    #[Computed]
    public function valuation(): array
    {
        $rows = $this->stockRows;

        return [
            'item_count' => $rows->where('on_hand', '>', 0)->count(),
            'total_units' => (float) $rows->sum('on_hand'),
            'total_value' => (float) $rows->sum('value'),
        ];
    }

    #[Computed]
    public function reorderExceptions()
    {
        return $this->stockRows->filter(fn ($row) => $row['status'] !== 'ok')->values();
    }

    #[Computed]
    public function expiringBatches()
    {
        $branchId = $this->branch_id;

        return Batch::with(['item', 'stockLevels' => function ($query) use ($branchId) {
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        }])
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays(30))
            ->orderBy('expiry_date')
            ->get()
            ->map(fn (Batch $batch) => [
                'batch' => $batch,
                'on_hand' => (float) $batch->stockLevels->sum('quantity_on_hand'),
                'expired' => $batch->expiry_date->isPast(),
            ])
            ->filter(fn ($row) => $row['on_hand'] > 0)
            ->values();
    }

    #[Computed]
    public function movements()
    {
        $query = StockMovement::with(['item', 'branch', 'performedBy'])
            ->whereDate('created_at', '>=', $this->from ?: today())
            ->whereDate('created_at', '<=', $this->to ?: today())
            ->latest();

        if ($this->branch_id) {
            $query->where('branch_id', $this->branch_id);
        }

        return $query->get();
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
        return "stock-movements-{$this->from}-to-{$this->to}.{$extension}";
    }

    public function exportCsv()
    {
        Gate::authorize('reports.view');

        $movements = $this->movements;
        $from = $this->from;
        $to = $this->to;
        $branchLabel = $this->branchLabel();

        return response()->streamDownload(function () use ($movements, $from, $to, $branchLabel) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [__('Stock Movement Report')]);
            fputcsv($handle, [__('Branch'), $branchLabel]);
            fputcsv($handle, [__('Period'), "{$from} to {$to}"]);
            fputcsv($handle, [__('Generated'), now()->toDateTimeString()]);
            fputcsv($handle, []);
            fputcsv($handle, [__('Date'), __('Branch'), __('Item'), __('Type'), __('Quantity'), __('Unit Cost'), __('Performed By')]);

            foreach ($movements as $movement) {
                fputcsv($handle, [
                    $movement->created_at->toDateTimeString(),
                    $movement->branch->name,
                    $movement->item->sku,
                    $movement->movement_type,
                    $movement->quantity,
                    number_format($movement->unit_cost, 2, '.', ''),
                    $movement->performedBy->name,
                ]);
            }

            fclose($handle);
        }, $this->exportFilename('csv'), ['Content-Type' => 'text/csv']);
    }

    public function exportPdf()
    {
        Gate::authorize('reports.view');

        $pdf = Pdf::loadView('pdf.stock-movement-report', [
            'tenantName' => Auth::user()->tenant->name,
            'branchName' => $this->branchLabel(),
            'from' => $this->from,
            'to' => $this->to,
            'generatedAt' => now()->toDateTimeString(),
            'movements' => $this->movements,
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->exportFilename('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }
}; ?>

<div x-data="{ tab: 'valuation' }">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Stock Report') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 flex flex-wrap items-end gap-4">
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

                <div x-show="tab === 'movements'" style="display: none;">
                    <x-input-label for="report-from" :value="__('From')" />
                    <x-text-input wire:model.live="from" id="report-from" class="block mt-1" type="date" />
                </div>
                <div x-show="tab === 'movements'" style="display: none;">
                    <x-input-label for="report-to" :value="__('To')" />
                    <x-text-input wire:model.live="to" id="report-to" class="block mt-1" type="date" />
                </div>

                <div class="flex gap-2 ml-auto" x-show="tab === 'movements'" style="display: none;">
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
                    <button type="button" x-on:click="tab = 'valuation'" :class="tab === 'valuation' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm">{{ __('Valuation') }}</button>
                    <button type="button" x-on:click="tab = 'exceptions'" :class="tab === 'exceptions' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm">{{ __('Reorder Exceptions') }}</button>
                    <button type="button" x-on:click="tab = 'expiring'" :class="tab === 'expiring' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm">{{ __('Expiring Items') }}</button>
                    <button type="button" x-on:click="tab = 'movements'" :class="tab === 'movements' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm">{{ __('Movement History') }}</button>
                </nav>
            </div>

            <!-- Valuation -->
            <div x-show="tab === 'valuation'" style="display: none;">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Items in Stock') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $this->valuation['item_count'] }}</div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Total Units') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ rtrim(rtrim(number_format($this->valuation['total_units'], 3), '0'), '.') }}</div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Total Value') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($this->valuation['total_value'], 2) }}</div>
                    </div>
                </div>
            </div>

            <!-- Reorder Exceptions -->
            <div x-show="tab === 'exceptions'" style="display: none;">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('SKU') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('On Hand') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Reorder Level') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($this->reorderExceptions as $row)
                                <tr wire:key="exception-{{ $row['item']->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $row['item']->sku }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ rtrim(rtrim(number_format($row['on_hand'], 3), '0'), '.') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $row['reorder_level'] }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $row['status'] === 'out' ? 'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400' : 'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400' }}">
                                            {{ $row['status'] === 'out' ? __('Out of Stock') : __('Low') }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No reorder exceptions.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Expiring Items -->
            <div x-show="tab === 'expiring'" style="display: none;">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('SKU') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Batch') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Expiry') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('On Hand') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($this->expiringBatches as $row)
                                <tr wire:key="expiring-{{ $row['batch']->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $row['batch']->item->sku }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $row['batch']->batch_number }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $row['batch']->expiry_date->format('d M Y') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ rtrim(rtrim(number_format($row['on_hand'], 3), '0'), '.') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $row['expired'] ? 'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400' : 'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400' }}">
                                            {{ $row['expired'] ? __('Expired') : __('Expiring Soon') }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing expiring in the next 30 days.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Movement History -->
            <div x-show="tab === 'movements'" style="display: none;">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Date') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Branch') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Item') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Type') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Quantity') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Performed By') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($this->movements as $movement)
                                <tr wire:key="movement-{{ $movement->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $movement->created_at->format('d M Y, H:i') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $movement->branch->name }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $movement->item->sku }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ ucfirst(str_replace('_', ' ', $movement->movement_type)) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm {{ $movement->quantity >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">{{ $movement->quantity >= 0 ? '+' : '' }}{{ $movement->quantity }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $movement->performedBy->name }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No movements in this range.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
