<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Item Velocity Report') }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin-top: 20px; margin-bottom: 4px; }
        .meta { color: #6b7280; margin-bottom: 16px; }
        .meta div { margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
        th { background-color: #f3f4f6; font-size: 10px; text-transform: uppercase; }
    </style>
</head>
<body>
    <h1>{{ __('Item Velocity Report') }}</h1>
    <div class="meta">
        <div>{{ __('Business Name') }}: {{ $tenantName }}</div>
        <div>{{ __('Branch') }}: {{ $branchName }}</div>
        <div>{{ __('Window') }}: {{ $windowDays }} {{ __('days') }}</div>
        <div>{{ __('Generated') }}: {{ $generatedAt }}</div>
    </div>

    <h2>{{ __('Fast Movers') }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('Units Sold') }}</th>
                <th>{{ __('Revenue') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($fastMovers as $row)
                <tr>
                    <td>{{ $row['item']->sku }}</td>
                    <td>{{ $row['units_sold'] }}</td>
                    <td>{{ number_format($row['revenue'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">{{ __('No sales in this window.') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>{{ __('Slow/Dead Stock') }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('On Hand') }}</th>
                <th>{{ __('Last Sold') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($slowMoving as $row)
                <tr>
                    <td>{{ $row['item']->sku }}</td>
                    <td>{{ $row['on_hand'] }}</td>
                    <td>{{ $row['last_sold_at']?->format('d M Y') ?? __('Never sold') }}</td>
                </tr>
            @empty
                <tr><td colspan="3">{{ __('No slow-moving items in this window.') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>{{ __('ABC Analysis') }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('Revenue') }}</th>
                <th>{{ __('Cumulative %') }}</th>
                <th>{{ __('Class') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($abcAnalysis as $row)
                <tr>
                    <td>{{ $row['item']->sku }}</td>
                    <td>{{ number_format($row['revenue'], 2) }}</td>
                    <td>{{ number_format($row['cumulative_pct'], 1) }}%</td>
                    <td>{{ $row['class'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4">{{ __('No sales in this window.') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>{{ __('Reorder Suggestions') }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('On Hand') }}</th>
                <th>{{ __('Reorder Level') }}</th>
                <th>{{ __('Suggested Qty') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($reorderSuggestions as $row)
                <tr>
                    <td>{{ $row['item']->sku }}</td>
                    <td>{{ $row['on_hand'] }}</td>
                    <td>{{ $row['reorder_level'] }}</td>
                    <td>{{ $row['suggested_qty'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4">{{ __('No items are under their reorder level.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
