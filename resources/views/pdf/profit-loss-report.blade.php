<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Profit & Loss Report') }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 13px; margin-top: 20px; margin-bottom: 4px; }
        .meta { color: #6b7280; margin-bottom: 16px; }
        .meta div { margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
        th { background-color: #f3f4f6; font-size: 10px; text-transform: uppercase; }
        .summary-table td { border: none; padding: 4px 12px 4px 0; }
        .summary-label { color: #6b7280; }
        .summary-value { font-weight: bold; font-size: 14px; }
        .note { color: #6b7280; font-size: 10px; margin-top: 8px; }
    </style>
</head>
<body>
    <h1>{{ __('Profit & Loss Report') }}</h1>
    <div class="meta">
        <div>{{ __('Business Name') }}: {{ $tenantName }}</div>
        <div>{{ __('Branch') }}: {{ $branchName }}</div>
        <div>{{ __('Period') }}: {{ $from }} - {{ $to }}</div>
        <div>{{ __('Generated') }}: {{ $generatedAt }}</div>
    </div>

    <table class="summary-table">
        <tr>
            <td class="summary-label">{{ __('Net Revenue') }}</td>
            <td class="summary-label">{{ __('Returns') }}</td>
            <td class="summary-label">{{ __('Net COGS') }}</td>
            <td class="summary-label">{{ __('Shrinkage') }}</td>
            <td class="summary-label">{{ __('Gross Profit') }}</td>
            <td class="summary-label">{{ __('Margin %') }}</td>
        </tr>
        <tr>
            <td class="summary-value">{{ number_format($summary['net_revenue'], 2) }}</td>
            <td class="summary-value">-{{ number_format($summary['returns_revenue'], 2) }}</td>
            <td class="summary-value">{{ number_format($summary['net_cogs'], 2) }}</td>
            <td class="summary-value">-{{ number_format($summary['shrinkage'], 2) }}</td>
            <td class="summary-value">{{ number_format($summary['net_gross_profit'], 2) }}</td>
            <td class="summary-value">{{ number_format($summary['gross_margin_pct'], 1) }}%</td>
        </tr>
    </table>
    <div class="note">{{ __('Gross profit only — operating expenses (rent, salaries, utilities, etc.) aren\'t tracked in DigiPlus and are not reflected here.') }}</div>

    <h2>{{ __('Daily Breakdown') }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Sales') }}</th>
                <th>{{ __('Revenue') }}</th>
                <th>{{ __('COGS') }}</th>
                <th>{{ __('Shrinkage') }}</th>
                <th>{{ __('Gross Profit') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($dailyBreakdown as $day)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d M Y') }}</td>
                    <td>{{ $day['count'] }}</td>
                    <td>{{ number_format($day['revenue'], 2) }}</td>
                    <td>{{ number_format($day['cogs'], 2) }}</td>
                    <td>{{ number_format($day['shrinkage'], 2) }}</td>
                    <td>{{ number_format($day['gross_profit'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">{{ __('No sales in this range.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>{{ __('By Category') }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ __('Category') }}</th>
                <th>{{ __('Revenue') }}</th>
                <th>{{ __('COGS') }}</th>
                <th>{{ __('Gross Profit') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($categoryBreakdown as $row)
                <tr>
                    <td>{{ $row['category'] }}</td>
                    <td>{{ number_format($row['revenue'], 2) }}</td>
                    <td>{{ number_format($row['cogs'], 2) }}</td>
                    <td>{{ number_format($row['gross_profit'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">{{ __('No sales in this range.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
