@php $brand = $order->brand_snapshot ?? []; @endphp
<x-mail::message>
# Your refund has been sent

Hello {{ data_get($order->shipping_address, 'first_name') }},

We have sent a refund of **{{ format_minor((int) $refund->getRawOriginal('amount_minor'), $refund->currency) }}**
for order **{{ $order->number }}** back to your original payment method.

<x-mail::panel>
Your payment provider has confirmed the refund has been issued. How long it
takes to show on your statement depends on your bank — usually a few working
days.
</x-mail::panel>

@if ($refund->reason)
**Reason:** {{ $refund->reason }}
@endif

Thanks,<br>
{{ $brand['name'] ?? branding('name') }}
</x-mail::message>
