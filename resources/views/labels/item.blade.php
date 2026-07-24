<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Label') }} {{ $item->sku }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 16px;
            background: #e5e7eb;
            font-family: Helvetica, Arial, sans-serif;
            color: #111827;
        }
        .toolbar {
            max-width: 700px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .print-button {
            padding: 10px 16px;
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
        .labels {
            max-width: 700px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .label {
            width: 50mm;
            height: 30mm;
            padding: 3mm;
            background: #fff;
            border: 1px solid #d1d5db;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
        }
        .label .name { font-size: 9px; font-weight: bold; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .label .sku { font-size: 8px; color: #4b5563; }
        .label .price { font-size: 10px; font-weight: bold; margin-top: 1mm; }
        .label .barcode { margin-top: 1mm; }
        .label .barcode svg { width: 44mm; height: auto; }
        .label .barcode-value { font-size: 7px; letter-spacing: 1px; }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .labels { max-width: none; }
            .label { border: 1px dashed #9ca3af; }
            @page { size: auto; margin: 4mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="print-button" onclick="window.print()">{{ __('Print') }}</button>
        <span>{{ __(':count label(s) for :sku', ['count' => $copies, 'sku' => $item->sku]) }}</span>
    </div>

    <div class="labels">
        @for ($i = 0; $i < $copies; $i++)
            <div class="label">
                <div class="name">{{ $item->product->name }}</div>
                <div class="sku">{{ $item->sku }}</div>
                <div class="price">{{ number_format($item->selling_price, 2) }}</div>
                <div class="barcode">{!! $barcodeSvg !!}</div>
                <div class="barcode-value">{{ $barcodeValue }}</div>
            </div>
        @endfor
    </div>
</body>
</html>
