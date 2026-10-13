@php
    use App\Support\Money;
    use App\Support\Quantity;
    $money = fn (int $minor) => Money::display($minor, $order->currency);
    $local = fn ($time) => $time?->copy()->timezone(\App\Support\Market::timezone())->format('j M Y, H:i');
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $order->number }}</title>
    @include('documents.styles')
</head>
<body>
<table class="head">
    <tr>
        <td>
            <div class="brand">mundalinq<span>.</span></div>
            <div class="tagline">{{ __('Fresh from local farms') }}</div>
        </td>
        <td class="doc">
            <h1>{{ $title }}</h1>
            <div>{{ __('Order') }} <strong>{{ $order->number }}</strong></div>
            <div class="muted">{{ __('Placed') }} {{ $local($order->created_at) }}</div>
            @if ($payment)
                <div class="muted">{{ __('Paid') }} {{ $local($payment->paid_at) }}</div>
            @elseif ($order->status === \App\Enums\OrderStatus::Cancelled)
                <div class="cancelled">{{ __('Cancelled: nothing was charged') }}</div>
            @else
                <div class="unpaid">{{ __('Not paid yet') }}</div>
            @endif
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="label">{{ __('Bought by') }}</div>
            <strong>{{ $order->buyer->name }}</strong><br>
            <span class="muted">{{ $order->buyer->phone }}@if ($order->buyer->email) · {{ $order->buyer->email }}@endif</span>
        </td>
        <td>
            <div class="label">{{ __('Sold by') }}</div>
            @foreach ($order->sellerOrders as $part)
                <strong>{{ $part->farm->name }}</strong>@if ($part->farm->primaryLocation), {{ $part->farm->primaryLocation->district }}@endif<br>
            @endforeach
        </td>
    </tr>
</table>

@foreach ($order->sellerOrders as $part)
    <h2>{{ $part->farm->name }} · {{ $part->number }}</h2>
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
        @if ($part->isPickup())
            {{ __('Collected at the farm') }}
        @else
            {{ __('Delivered to') }} {{ collect([$part->delivery_address['address_line'] ?? null, $part->delivery_address['district'] ?? null])->filter()->join(', ') }}
        @endif
        · {{ __('Status') }}: {{ str_replace('_', ' ', $part->status->value) }}
    </div>
@endforeach

<table class="totals">
    <tr><td>{{ __('Produce') }}</td><td class="num">{{ $money($order->subtotal_minor) }}</td></tr>
    <tr><td>{{ __('Delivery') }}</td><td class="num">{{ $money($order->delivery_fee_minor) }}</td></tr>
    <tr class="grand"><td>{{ $payment ? __('Total paid') : __('Total') }}</td><td class="num">{{ $money($order->total_minor) }}</td></tr>
</table>

@if ($payment)
    <div class="note">
        {{ __('Paid by mobile money') }} ({{ ucfirst($payment->provider) }}) {{ __('from') }} {{ $payment->payer_phone }}@if ($payment->provider_reference), {{ __('reference') }} {{ $payment->provider_reference }}@endif.
    </div>
@endif

@if ($order->note)
    <div class="note">{{ __('Your note') }}: {{ $order->note }}</div>
@endif

<div class="foot">
    {{ __('MundaLinq is a marketplace: each farm sells its own produce, and MundaLinq collects payment on the farms\' behalf. Prices are in :currency.', ['currency' => $order->currency]) }}
    {{ __('Created') }} {{ $local(now()) }}.
</div>
</body>
</html>
