@php
    use App\Support\Money;
    use App\Support\Quantity;
    $money = fn (int $minor) => Money::display($minor, $order->currency);
    $local = fn ($time) => $time?->copy()->timezone(\App\Support\Market::timezone())->format('j M Y, H:i');
    $accepted = $part->accepted_at !== null;
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Sale') }} {{ $part->number }}</title>
    @include('documents.styles')
</head>
<body>
<table class="head">
    <tr>
        <td>
            <div class="brand">mundalinq<span>.</span></div>
            <div class="tagline">{{ __('Sale record for :farm', ['farm' => $part->farm->name]) }}</div>
        </td>
        <td class="doc">
            <h1>{{ __('Sale') }} {{ $part->number }}</h1>
            <div class="muted">{{ __('Ordered') }} {{ $local($order->created_at) }}</div>
            @if ($paid)
                <div class="muted">{{ __('Paid') }} {{ $local($order->paid_at) }}</div>
            @else
                <div class="unpaid">{{ __('Not paid yet') }}</div>
            @endif
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="label">{{ __('Farm') }}</div>
            <strong>{{ $part->farm->name }}</strong><br>
            <span class="muted">{{ $part->farm->contact_phone }}@if ($part->farm->primaryLocation) · {{ $part->farm->primaryLocation->district }}, {{ $part->farm->primaryLocation->province }}@endif</span>
        </td>
        <td>
            <div class="label">{{ __('Buyer') }}</div>
            <strong>{{ $order->buyer->name }}</strong><br>
            {{-- The buyer's number is shared with the farm once it accepts the order. --}}
            <span class="muted">{{ $accepted ? $order->buyer->phone : __('Shown once you accept') }}</span>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
    <tr>
        <th>{{ __('Item') }}</th>
        <th class="num">{{ __('Quantity') }}</th>
        <th class="num">{{ __('Price') }}</th>
        <th class="num">{{ __('Amount') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($part->items as $item)
        @php $quantity = Quantity::format($item->confirmed_quantity ?? $item->quantity); @endphp
        <tr>
            <td>{{ $item->product_title }}@if ($item->variant_label) ({{ $item->variant_label }})@endif</td>
            <td class="num">{{ $quantity }} {{ (float) $quantity === 1.0 ? $item->unit_name : $item->unit_plural_name }}</td>
            <td class="num">{{ $money($item->unit_price_minor) }}</td>
            <td class="num">{{ $money($item->line_total_minor) }}</td>
        </tr>
    @endforeach
    @if ($part->delivery_fee_minor > 0)
        <tr>
            <td colspan="3">{{ __('Delivery') }}</td>
            <td class="num">{{ $money($part->delivery_fee_minor) }}</td>
        </tr>
    @endif
    </tbody>
</table>
<div class="muted" style="margin-top:4px">
    {{ $part->isPickup() ? __('Collection at the farm') : __('Delivery') }} · {{ __('Status') }}: {{ str_replace('_', ' ', $part->status->value) }}
</div>

<table class="totals">
    <tr><td>{{ __('Sale total') }}</td><td class="num">{{ $money($part->total_minor) }}</td></tr>
    <tr><td>{{ __('MundaLinq fee') }}</td><td class="num">−{{ $money($fees) }}</td></tr>
    @if ($refunds > 0)
        <tr><td>{{ __('Refunded to the buyer') }}</td><td class="num">−{{ $money($refunds) }}</td></tr>
    @endif
    <tr class="grand"><td>{{ __('You receive') }}</td><td class="num">{{ $money($paid ? $receives : 0) }}</td></tr>
</table>

<div class="foot">
    {{ __('MundaLinq collects the buyer\'s payment and pays the farm its earnings, less the platform fee on produce (not on delivery). Earnings can be paid out once the buyer has the order and the time to report a problem has passed.') }}
    {{ __('Created') }} {{ $local(now()) }}.
</div>
</body>
</html>
