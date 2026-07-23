<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Shift Variance Report') }}</title>
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
    <h1>{{ __('Shift Variance Report') }}</h1>
    <div class="meta">
        <div>{{ __('Business Name') }}: {{ $tenantName }}</div>
        <div>{{ __('Branch') }}: {{ $branchName }}</div>
        <div>{{ __('Period') }}: {{ $from }} - {{ $to }}</div>
        <div>{{ __('Generated') }}: {{ $generatedAt }}</div>
    </div>

    <table class="summary-table">
        <tr>
            <td class="summary-label">{{ __('Shifts') }}</td>
            <td class="summary-label">{{ __('Discrepancies') }}</td>
            <td class="summary-label">{{ __('Total Variance') }}</td>
        </tr>
        <tr>
            <td class="summary-value">{{ $summary['count'] }}</td>
            <td class="summary-value">{{ $summary['discrepancies'] }}</td>
            <td class="summary-value">{{ number_format($summary['total_variance'], 2) }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>{{ __('Cashier') }}</th>
                <th>{{ __('Branch') }}</th>
                <th>{{ __('Opened') }}</th>
                <th>{{ __('Closed') }}</th>
                <th>{{ __('Opening Float') }}</th>
                <th>{{ __('Counted Cash') }}</th>
                <th>{{ __('Expected Cash') }}</th>
                <th>{{ __('Variance') }}</th>
                <th>{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($shifts as $shift)
                <tr>
                    <td>{{ $shift->cashier->name }}</td>
                    <td>{{ $shift->branch->name }}</td>
                    <td>{{ $shift->opened_at->format('d M Y, H:i') }}</td>
                    <td>{{ $shift->closed_at?->format('d M Y, H:i') ?? '-' }}</td>
                    <td>{{ number_format($shift->opening_float, 2) }}</td>
                    <td>{{ $shift->closing_float_counted !== null ? number_format($shift->closing_float_counted, 2) : '-' }}</td>
                    <td>{{ $shift->expected_cash !== null ? number_format($shift->expected_cash, 2) : '-' }}</td>
                    <td>{{ $shift->variance !== null ? number_format($shift->variance, 2) : '-' }}</td>
                    <td>{{ ucfirst($shift->status) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">{{ __('No shifts in this range.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
