@php $brand = $order->brand_snapshot ?? []; @endphp
<x-mail::message>
# Thank you — we have your order

Hello {{ data_get($order->shipping_address, 'first_name') }},

We have received your payment for order **{{ $order->number }}**.

<x-mail::panel>
We review every order before sending it to our fulfilment partner. You will
get another email with tracking as soon as each parcel is dispatched.
</x-mail::panel>

<x-mail::table>
| Item | Qty | Price |
|:-----|:---:|------:|
@foreach ($order->items as $item)
| {{ $item->name }}{{ $item->option_summary ? ' — '.$item->option_summary : '' }} | {{ $item->quantity }} | {{ format_minor($item->lineTotalMinor(), $order->currency) }} |
@endforeach
</x-mail::table>

**Subtotal:** {{ format_minor((int) $order->getRawOriginal('subtotal_minor'), $order->currency) }}<br>
@if ((int) $order->getRawOriginal('discount_minor') > 0)
**Discount:** −{{ format_minor((int) $order->getRawOriginal('discount_minor'), $order->currency) }}<br>
@endif
**Delivery:** {{ (int) $order->getRawOriginal('shipping_minor') === 0 ? 'Free' : format_minor((int) $order->getRawOriginal('shipping_minor'), $order->currency) }}<br>
**Tax:** {{ format_minor((int) $order->getRawOriginal('tax_minor'), $order->currency) }}<br>
**Total paid:** {{ format_minor((int) $order->getRawOriginal('total_minor'), $order->currency) }}

@isset($trackingUrl)
<x-mail::button :url="$trackingUrl">
Track your order
</x-mail::button>
@endisset

Thanks,<br>
{{ $brand['name'] ?? branding('name') }}
</x-mail::message>
