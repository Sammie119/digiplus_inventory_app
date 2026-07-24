<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Sales Report') }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { color: #6b7280; margin-bottom: 16px; }
        .meta div { margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
        th { background-color: #f3f4f6; font-size: 10px; text-transform: uppercase; }
        .summary-table td { border: none; padding: 4px 12px 4px 0; }
        .summary-label { color: #6b7280; }
        .summary-value { font-weight: bold; font-size: 14px; }
    </style>
</head>
<body>
    <h1>{{ __('Sales Report') }}</h1>
    <div class="meta">
        <div>{{ __('Business Name   ') }}: {{ $tenantName }}</div>
        <div>{{ __('Branch') }}: {{ $branchName }}</div>
        <div>{{ __('Period') }}: {{ $from }} - {{ $to }}</div>
        <div>{{ __('Generated') }}: {{ $generatedAt }}</div>
    </div>

    <table class="summary-table">
        <tr>
            <td class="summary-label">{{ __('Sales') }}</td>
            <td class="summary-label">{{ __('Revenue') }}</td>
            <td class="summary-label">{{ __('Tax') }}</td>
            <td class="summary-label">{{ __('Cost') }}</td>
            <td class="summary-label">{{ __('Margin') }}</td>
            <td class="summary-label">{{ __('Margin %') }}</td>
        </tr>
        <tr>
            <td class="summary-value">{{ $summary['count'] }}</td>
            <td class="summary-value">{{ number_format($summary['revenue'], 2) }}</td>
            <td class="summary-value">{{ number_format($summary['tax'], 2) }}</td>
            <td class="summary-value">{{ number_format($summary['cost'], 2) }}</td>
            <td class="summary-value">{{ number_format($summary['margin'], 2) }}</td>
            <td class="summary-value">{{ number_format($summary['margin_pct'], 1) }}%</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Sales') }}</th>
                <th>{{ __('Revenue') }}</th>
                <th>{{ __('Cost') }}</th>
                <th>{{ __('Margin') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($dailyBreakdown as $day)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d M Y') }}</td>
                    <td>{{ $day['count'] }}</td>
                    <td>{{ number_format($day['revenue'], 2) }}</td>
                    <td>{{ number_format($day['cost'], 2) }}</td>
                    <td>{{ number_format($day['margin'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">{{ __('No sales in this range.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
