<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Order #{{ $order->id }}</title>
</head>
<body style="font-family: system-ui, sans-serif; color: #1f2937;">
    <h1 style="font-size: 20px;">Thank you for your order, {{ $order->user->name }}</h1>

    <p>Order <strong>#{{ $order->id }}</strong> was placed on
        {{ $order->created_at->toDayDateTimeString() }} and is currently
        <strong>{{ $order->status->value }}</strong>.</p>

    <table cellpadding="6" cellspacing="0" border="0" style="border-collapse: collapse; width: 100%;">
        <thead>
            <tr style="text-align: left; border-bottom: 1px solid #e5e7eb;">
                <th>Item</th>
                <th>Qty</th>
                <th style="text-align: right;">Unit price</th>
                <th style="text-align: right;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td>{{ $item->product->name }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td style="text-align: right;">{{ $item->unit_price }}</td>
                    <td style="text-align: right;">{{ $item->subtotal }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="margin-top: 16px;">
        Subtotal: {{ $order->subtotal }}<br>
        Shipping: {{ $order->shipping_cost }}<br>
        <strong>Total: {{ $order->total }}</strong>
    </p>

    <p style="color: #6b7280; font-size: 13px;">LibyaMarket</p>
</body>
</html>
