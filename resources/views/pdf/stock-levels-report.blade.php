<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Stock Levels') }}</title>
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
    <h1>{{ __('Stock Levels') }}</h1>
    <div class="meta">
        <div>{{ __('Business Name') }}: {{ $tenantName }}</div>
        <div>{{ __('Branch') }}: {{ $branchName }}</div>
        <div>{{ __('Generated') }}: {{ $generatedAt }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('Product') }}</th>
                <th>{{ __('Price (incl. tax)') }}</th>
                <th>{{ __('On Hand') }}</th>
                <th>{{ __('Reorder Level') }}</th>
                <th>{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['item']->sku }}</td>
                    <td>{{ $row['item']->product?->name }}</td>
                    <td>{{ number_format($row['price_incl_tax'], 2) }}</td>
                    <td>{{ rtrim(rtrim(number_format($row['on_hand'], 3), '0'), '.') }}</td>
                    <td>{{ $row['reorder_level'] }}</td>
                    <td>{{ ucfirst($row['status']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">{{ __('No items found.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
