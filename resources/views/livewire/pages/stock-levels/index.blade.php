<?php

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Item;
use App\Models\TaxRate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public ?int $branch_id = null;

    public function mount(): void
    {
        Gate::authorize('stock.view');

        $actor = Auth::user();
        $this->branch_id = $actor->hasRole('Business Owner') ? null : $actor->branch_id;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'branch_id'], true)) {
            $this->resetPage();
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
    public function taxRates()
    {
        return TaxRate::where('tenant_id', Auth::user()->tenant_id)->get();
    }

    private function priceInclTax(Item $item): float
    {
        $categoryId = $item->product?->category_id;

        $taxAmount = $this->taxRates->sum(function ($rate) use ($item, $categoryId) {
            $applies = $rate->applies_to === 'all'
                || ($rate->applies_to === 'category' && $categoryId !== null && $rate->category_id === $categoryId);

            return $applies ? round((float) $item->selling_price * (float) $rate->rate_percentage / 100, 2) : 0;
        });

        return round((float) $item->selling_price + $taxAmount, 2);
    }

    private function itemsQuery()
    {
        $branchId = $this->branch_id;

        return Item::with(['baseUnit', 'product', 'stockLevels' => function ($query) use ($branchId) {
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        }])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('sku', 'like', '%'.$this->search.'%')
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$this->search.'%'));
                });
            })
            ->orderByDesc('id');
    }

    private function mapRow(Item $item): array
    {
        $branchId = $this->branch_id;
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
            'price_incl_tax' => $this->priceInclTax($item),
        ];
    }

    #[Computed]
    public function rows()
    {
        return $this->itemsQuery()->paginate(20)->through(fn (Item $item) => $this->mapRow($item));
    }

    private function branchLabel(): string
    {
        if (! $this->branch_id) {
            return __('All Branches (Consolidated)');
        }

        return $this->branchOptions->firstWhere('id', $this->branch_id)?->name ?? __('All Branches (Consolidated)');
    }

    private function exportFilename(string $extension): string
    {
        return 'stock-levels-'.now()->toDateString().".{$extension}";
    }

    public function exportCsv()
    {
        Gate::authorize('stock.view');

        $rows = $this->itemsQuery()->get()->map(fn (Item $item) => $this->mapRow($item));
        $branchLabel = $this->branchLabel();

        return response()->streamDownload(function () use ($rows, $branchLabel) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [__('Stock Levels')]);
            fputcsv($handle, [__('Branch'), $branchLabel]);
            fputcsv($handle, [__('Generated'), now()->toDateTimeString()]);
            fputcsv($handle, []);
            fputcsv($handle, [__('SKU'), __('Product'), __('Price (incl. tax)'), __('On Hand'), __('Reorder Level'), __('Status')]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['item']->sku,
                    $row['item']->product?->name,
                    number_format($row['price_incl_tax'], 2, '.', ''),
                    rtrim(rtrim(number_format($row['on_hand'], 3), '0'), '.'),
                    $row['reorder_level'],
                    ucfirst($row['status']),
                ]);
            }

            fclose($handle);
        }, $this->exportFilename('csv'), ['Content-Type' => 'text/csv']);
    }

    public function exportPdf()
    {
        Gate::authorize('stock.view');

        $pdf = Pdf::loadView('pdf.stock-levels-report', [
            'tenantName' => Auth::user()->tenant->name,
            'branchName' => $this->branchLabel(),
            'generatedAt' => now()->toDateTimeString(),
            'rows' => $this->itemsQuery()->get()->map(fn (Item $item) => $this->mapRow($item)),
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->exportFilename('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function batchRowsForItem(int $itemId)
    {
        $branchId = $this->branch_id;

        return Batch::where('item_id', $itemId)
            ->with(['stockLevels' => function ($query) use ($branchId) {
                if ($branchId) {
                    $query->where('branch_id', $branchId);
                }
            }])
            ->fefoOrder()
            ->get()
            ->map(fn (Batch $batch) => [
                'batch' => $batch,
                'on_hand' => (float) $batch->stockLevels->sum('quantity_on_hand'),
            ]);
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Stock Levels') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="flex flex-col sm:flex-row gap-3 sm:items-end sm:justify-between">
                <div class="flex-1 max-w-sm">
                    <x-input-label for="stock-search" :value="__('Search')" />
                    <x-text-input wire:model.live="search" id="stock-search" class="block mt-1 w-full" type="text" :placeholder="__('SKU or product name…')" />
                </div>

                @if ($this->branchOptions->count() > 1)
                    <div class="w-full sm:w-56">
                        <x-input-label for="stock-branch" :value="__('Branch')" />
                        <select wire:model.live="branch_id" id="stock-branch" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                            <option value="">{{ __('All Branches (Consolidated)') }}</option>
                            @foreach ($this->branchOptions as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="flex gap-2 sm:ml-auto">
                    <x-secondary-button type="button" wire:click="exportCsv">
                        {{ __('Export CSV') }}
                    </x-secondary-button>
                    <x-secondary-button type="button" wire:click="exportPdf">
                        {{ __('Export PDF') }}
                    </x-secondary-button>
                </div>
            </div>

            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->rows as $row)
                    <div wire:key="stock-card-{{ $row['item']->id }}" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $row['item']->sku }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ $row['item']->product?->name }}</div>
                            </div>
                            <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                @if ($row['status'] === 'out') bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400
                                @elseif ($row['status'] === 'low') bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400
                                @else bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400
                                @endif
                            ">
                                {{ $row['status'] === 'out' ? __('Out of Stock') : ($row['status'] === 'low' ? __('Low') : __('OK')) }}
                            </span>
                        </div>
                        <div class="mt-3 flex items-center justify-between text-sm">
                            <span class="text-gray-500 dark:text-gray-400">{{ __('Price (incl. tax)') }}: {{ number_format($row['price_incl_tax'], 2) }}</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between text-sm">
                            <span class="text-gray-900 dark:text-gray-100">{{ __('On Hand') }}: {{ rtrim(rtrim(number_format($row['on_hand'], 3), '0'), '.') }}</span>
                            @if ($row['item']->track_batches)
                                <button type="button" x-on:click="$dispatch('open-modal', 'batches-{{ $row['item']->id }}')" class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View Batches') }}</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No items found.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('SKU') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Product') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Price (incl. tax)') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('On Hand') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Reorder Level') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->rows as $row)
                            <tr wire:key="stock-row-{{ $row['item']->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $row['item']->sku }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $row['item']->product?->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($row['price_incl_tax'], 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ rtrim(rtrim(number_format($row['on_hand'], 3), '0'), '.') }} {{ $row['item']->baseUnit->abbreviation }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $row['reorder_level'] }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                        @if ($row['status'] === 'out') bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400
                                        @elseif ($row['status'] === 'low') bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400
                                        @else bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400
                                        @endif
                                    ">
                                        {{ $row['status'] === 'out' ? __('Out of Stock') : ($row['status'] === 'low' ? __('Low') : __('OK')) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    @if ($row['item']->track_batches)
                                        <button type="button" x-on:click="$dispatch('open-modal', 'batches-{{ $row['item']->id }}')" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View Batches') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No items found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $this->rows->links() }}
        </div>
    </div>

    @foreach ($this->rows as $row)
        @if ($row['item']->track_batches)
            <x-modal name="batches-{{ $row['item']->id }}" focusable max-width="md">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Batches') }} - {{ $row['item']->sku }}</h2>

                    <div class="mt-4 overflow-hidden border border-gray-200 dark:border-gray-700 rounded-md">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Batch') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Expiry') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('On Hand') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach ($this->batchRowsForItem($row['item']->id) as $batchRow)
                                    <tr>
                                        <td class="px-3 py-2 whitespace-nowrap text-gray-900 dark:text-gray-100">{{ $batchRow['batch']->batch_number }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $batchRow['batch']->expiry_date?->format('d M Y') ?? '—' }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ rtrim(rtrim(number_format($batchRow['on_hand'], 3), '0'), '.') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
                    </div>
                </div>
            </x-modal>
        @endif
    @endforeach
</div>
