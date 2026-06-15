<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 12px; }
        h1 { font-size: 22px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { border: 1px solid #d1d5db; padding: 7px; text-align: left; }
        th { background: #f3f4f6; }
        .muted { color: #6b7280; }
        .total { text-align: right; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="muted">Number: {{ $document->number }} | Status: {{ $document->status }}</p>
    <p>
        <strong>{{ $partyLabel }}:</strong>
        {{ $document->customer?->name ?? $document->vendor?->name }}
    </p>
    <table>
        <thead>
            <tr>
                <th>Product</th><th>Description</th><th>Quantity</th><th>Unit price</th><th>Discount</th><th>Tax</th><th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->lines as $line)
                <tr>
                    <td>{{ $line->product?->name }}</td>
                    <td>{{ $line->description }}</td>
                    <td>{{ number_format((float) $line->quantity, 2) }}</td>
                    <td>{{ $document->currency }} {{ number_format((float) $line->unit_price, 2) }}</td>
                    <td>{{ $document->currency }} {{ number_format((float) $line->discount_amount, 2) }}</td>
                    <td>{{ $document->currency }} {{ number_format((float) $line->tax_amount, 2) }}</td>
                    <td>{{ $document->currency }} {{ number_format((float) $line->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="total">Subtotal: {{ $document->currency }} {{ number_format((float) $document->subtotal, 2) }}</p>
    <p class="total">Discount: {{ $document->currency }} {{ number_format((float) $document->discount_total, 2) }}</p>
    <p class="total">Tax: {{ $document->currency }} {{ number_format((float) $document->tax_total, 2) }}</p>
    <p class="total">Total: {{ $document->currency }} {{ number_format((float) $document->total, 2) }}</p>
    @if ($document->notes)
        <p><strong>Notes:</strong> {{ $document->notes }}</p>
    @endif
</body>
</html>
