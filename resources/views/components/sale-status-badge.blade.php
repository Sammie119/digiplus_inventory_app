@props(['sale'])

<span
    @class([
        'shrink-0 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
        'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400' => $sale->status === 'void',
        'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400' => $sale->status !== 'void' && $sale->voided_by && ! $sale->void_approved_by,
        'bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400' => $sale->status !== 'void' && ! ($sale->voided_by && ! $sale->void_approved_by),
    ])
>
    @if ($sale->status === 'void')
        {{ __('Void') }}
    @elseif ($sale->voided_by && ! $sale->void_approved_by)
        {{ __('Void Pending') }}
    @else
        {{ __('Completed') }}
    @endif
</span>
