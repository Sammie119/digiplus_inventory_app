<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Stock Movement Report') }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { color: #6b7280; margin-bottom: 16px; }
        .meta div { margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
        th { background-color: #f3f4f6; font-size: 10px; text-transform: uppercase; }
    </style>
</head>
<body>
    <h1>{{ __('Stock Movement Report') }}</h1>
    <div class="meta">
        <div>{{ __('Business Name') }}: {{ $tenantName }}</div>
        <div>{{ __('Branch') }}: {{ $branchName }}</div>
        <div>{{ __('Period') }}: {{ $from }} - {{ $to }}</div>
        <div>{{ __('Generated') }}: {{ $generatedAt }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Branch') }}</th>
                <th>{{ __('Item') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Quantity') }}</th>
                <th>{{ __('Unit Cost') }}</th>
                <th>{{ __('Performed By') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($movements as $movement)
                <tr>
                    <td>{{ $movement->created_at->format('d M Y, H:i') }}</td>
                    <td>{{ $movement->branch->name }}</td>
                    <td>{{ $movement->item->sku }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $movement->movement_type)) }}</td>
                    <td>{{ $movement->quantity }}</td>
                    <td>{{ number_format($movement->unit_cost, 2) }}</td>
                    <td>{{ $movement->performedBy->name }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">{{ __('No movements in this range.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
