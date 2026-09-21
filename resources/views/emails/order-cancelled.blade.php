@php $brand = $order->brand_snapshot ?? []; @endphp
<x-mail::message>
# Order {{ $order->number }} has been cancelled

Hello {{ data_get($order->shipping_address, 'first_name') }},

@if ($reason)
{{ $reason }}
@endif

@if ((int) $order->getRawOriginal('refunded_minor') > 0)
We have sent a refund of **{{ format_minor((int) $order->getRawOriginal('refunded_minor'), $order->currency) }}**
back to your original payment method. Depending on your bank it can take a
few working days to appear.
@else
If you were charged for this order, your refund is being processed and we
will email you again once your payment provider confirms it has been sent.
@endif

Thanks,<br>
{{ $brand['name'] ?? branding('name') }}
</x-mail::message>
