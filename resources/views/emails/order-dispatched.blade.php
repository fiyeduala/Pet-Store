@php $brand = $order->brand_snapshot ?? []; @endphp
<x-mail::message>
# Your order is on its way

Hello {{ data_get($order->shipping_address, 'first_name') }},

@if ($order->shipments->count() > 1)
Part of order **{{ $order->number }}** has been dispatched. Your items are
stocked in different warehouses, so they travel separately.
@else
Order **{{ $order->number }}** has been dispatched.
@endif

@foreach ($shipments as $shipment)
<x-mail::panel>
**{{ $shipment->carrier ?: 'Carrier' }}**
@if ($shipment->tracking_number)
Tracking: {{ $shipment->tracking_number }}
@endif

{{ $shipment->estimateLabel() }}
</x-mail::panel>
@endforeach

@isset($trackingUrl)
<x-mail::button :url="$trackingUrl">
View your order
</x-mail::button>
@endisset

Thanks,<br>
{{ $brand['name'] ?? branding('name') }}
</x-mail::message>
