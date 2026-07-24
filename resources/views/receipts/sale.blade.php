<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Receipt') }} {{ $sale->receipt_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 16px;
            background: #e5e7eb;
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            color: #111827;
        }
        .receipt {
            max-width: 302px;
            margin: 0 auto;
            background: #fff;
            padding: 16px;
        }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .divider { border-top: 1px dashed #111827; margin: 8px 0; }
        .meta div { margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        td { padding: 2px 0; vertical-align: top; }
        .totals .row { margin-bottom: 2px; }
        .footer { margin-top: 12px; }
        .print-button {
            display: block;
            width: 100%;
            margin: 12px auto 0;
            max-width: 302px;
            padding: 10px;
            font-family: inherit;
            font-size: 13px;
            font-weight: bold;
            color: #fff;
            background: #4f46e5;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        .print-button:hover { background: #4338ca; }

        @media print {
            body { background: #fff; padding: 0; }
            .receipt { max-width: none; padding: 0; }
            .print-button { display: none; }
            @page { size: 80mm auto; margin: 4mm; }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="center">
            <div class="bold" style="font-size: 14px;">{{ $tenantName }}</div>
            <div>{{ $sale->branch->name }}</div>
        </div>

        <div class="divider"></div>

        <div class="meta">
            <div>{{ __('Receipt') }}: {{ $sale->receipt_number }}</div>
            <div>{{ __('Date') }}: {{ $sale->created_at->format('d M Y, H:i') }}</div>
            <div>{{ __('Cashier') }}: {{ $sale->cashier->name }}</div>
            @if ($sale->customer)
                <div>{{ __('Customer') }}: {{ $sale->customer->name }}</div>
            @endif
            @if ($sale->status === 'void')
                <div class="bold">{{ __('VOIDED') }}</div>
            @endif
        </div>

        <div class="divider"></div>

        <table>
            @foreach ($sale->items as $line)
                <tr>
                    <td colspan="2">{{ $line->item->sku }}</td>
                </tr>
                <tr>
                    <td>{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }} &times; {{ number_format($line->unit_price, 2) }}</td>
                    <td style="text-align: right;">{{ number_format($line->line_total, 2) }}</td>
                </tr>
            @endforeach
        </table>

        <div class="divider"></div>

        <div class="totals">
            <div class="row">
                <span>{{ __('Subtotal') }}</span>
                <span>{{ number_format($sale->subtotal, 2) }}</span>
            </div>
            @if ($sale->tax_total > 0)
                <div class="row">
                    <span>{{ __('Tax') }}</span>
                    <span>{{ number_format($sale->tax_total, 2) }}</span>
                </div>
            @endif
            <div class="row bold">
                <span>{{ __('Grand Total') }}</span>
                <span>{{ number_format($sale->grand_total, 2) }}</span>
            </div>
        </div>

        <div class="divider"></div>

        <div class="payments">
            @foreach ($sale->payments as $payment)
                <div class="row">
                    <span>{{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}</span>
                    <span>{{ number_format($payment->amount, 2) }}</span>
                </div>
            @endforeach
        </div>

        <div class="divider"></div>

        <div class="center footer">
            {{ __('Thank you for shopping with us!') }}
        </div>
    </div>

    <button type="button" class="print-button" onclick="window.print()">{{ __('Print') }}</button>
</body>
</html>
