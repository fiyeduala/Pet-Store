@props(['order', 'showActions' => false])

<div class="space-y-8">
    <div class="card p-5 lg:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg">Order {{ $order->number }}</h2>
                <p class="text-sm text-ink-500">
                    Placed {{ $order->placed_at?->timezone(branding('display_timezone') ?: config('petstore.display_timezone'))->format('j M Y, g:ia') }}
                </p>
            </div>
            <x-store.order-status :order="$order" />
        </div>

        @if ($order->is_demo)
            <x-ui.alert type="warning" class="mt-4" title="Demonstration order">
                This order was created with simulated payment and supplier data. No money was taken and nothing will ship.
            </x-ui.alert>
        @endif

        @if ($order->isOnHold() && $order->hold_reason)
            <x-ui.alert type="warning" class="mt-4" title="We have paused this order">
                {{ $order->hold_reason }} We will contact you before doing anything further.
            </x-ui.alert>
        @endif
    </div>

    {{-- Shipments. Each parcel is tracked separately. --}}
    @if ($order->shipments->isNotEmpty())
        <div class="card p-5 lg:p-6">
            <h2 class="mb-4 text-lg">
                {{ $order->shipments->count() > 1 ? $order->shipments->count().' parcels' : 'Delivery' }}
            </h2>
            <ul class="divide-y divide-ink-900/8">
                @foreach ($order->shipments as $index => $shipment)
                    <li class="py-4 first:pt-0 last:pb-0">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="font-medium text-ink-900">
                                    Parcel {{ $index + 1 }}
                                    @if ($shipment->carrier)
                                        <span class="text-ink-500">— {{ $shipment->carrier }}</span>
                                    @endif
                                </p>
                                <p class="text-sm text-ink-500">{{ $shipment->estimateLabel() }}</p>
                                @if ($shipment->isEvidencedDelivered())
                                    <p class="mt-1 text-sm text-emerald-700">
                                        Delivered {{ $shipment->delivered_at->format('j M Y') }}
                                    </p>
                                @endif
                            </div>
                            @if ($shipment->tracking_number)
                                <div class="text-right text-sm">
                                    <p class="text-ink-500">Tracking</p>
                                    @if ($shipment->tracking_url)
                                        <a href="{{ $shipment->tracking_url }}" rel="noopener noreferrer" target="_blank"
                                           class="font-mono text-ink-900 underline">{{ $shipment->tracking_number }}</a>
                                    @else
                                        <span class="font-mono text-ink-900">{{ $shipment->tracking_number }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card p-5 lg:p-6">
        <h2 class="mb-4 text-lg">Items</h2>
        <ul class="divide-y divide-ink-900/8">
            @foreach ($order->items as $item)
                <li class="flex items-start justify-between gap-3 py-3">
                    <div>
                        <p class="font-medium text-ink-900">{{ $item->name }}</p>
                        @if ($item->option_summary)
                            <p class="text-sm text-ink-500">{{ $item->option_summary }}</p>
                        @endif
                        <p class="text-sm text-ink-400">Quantity {{ $item->quantity }}</p>
                        @if ($item->quantity_refunded > 0)
                            <p class="text-sm text-ink-500">{{ $item->quantity_refunded }} refunded</p>
                        @endif
                    </div>
                    <span class="text-sm">{{ format_minor($item->lineTotalMinor(), $order->currency) }}</span>
                </li>
            @endforeach
        </ul>

        <dl class="mt-4 space-y-2 border-t border-ink-900/8 pt-4 text-sm">
            <div class="flex justify-between"><dt class="text-ink-600">Subtotal</dt>
                <dd>{{ format_minor((int) $order->getRawOriginal('subtotal_minor'), $order->currency) }}</dd></div>
            @if ((int) $order->getRawOriginal('discount_minor') > 0)
                <div class="flex justify-between text-emerald-700"><dt>Discount</dt>
                    <dd>&minus;{{ format_minor((int) $order->getRawOriginal('discount_minor'), $order->currency) }}</dd></div>
            @endif
            <div class="flex justify-between"><dt class="text-ink-600">Delivery</dt>
                <dd>{{ (int) $order->getRawOriginal('shipping_minor') === 0 ? 'Free' : format_minor((int) $order->getRawOriginal('shipping_minor'), $order->currency) }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-600">Tax</dt>
                <dd>{{ format_minor((int) $order->getRawOriginal('tax_minor'), $order->currency) }}</dd></div>
            <div class="flex justify-between border-t border-ink-900/8 pt-2 text-base font-semibold text-ink-900">
                <dt>Total</dt><dd>{{ format_minor((int) $order->getRawOriginal('total_minor'), $order->currency) }}</dd></div>
            @if ((int) $order->getRawOriginal('refunded_minor') > 0)
                <div class="flex justify-between text-ink-600"><dt>Refunded</dt>
                    <dd>&minus;{{ format_minor((int) $order->getRawOriginal('refunded_minor'), $order->currency) }}</dd></div>
            @endif
        </dl>
    </div>

    <div class="card p-5 lg:p-6">
        <h2 class="mb-3 text-lg">Delivery address</h2>
        <address class="text-sm not-italic text-ink-600">
            {{ data_get($order->shipping_address, 'first_name') }} {{ data_get($order->shipping_address, 'last_name') }}<br>
            {{ data_get($order->shipping_address, 'line1') }}<br>
            @if (data_get($order->shipping_address, 'line2'))
                {{ data_get($order->shipping_address, 'line2') }}<br>
            @endif
            {{ data_get($order->shipping_address, 'city') }},
            {{ data_get($order->shipping_address, 'state') }}
            {{ data_get($order->shipping_address, 'postal_code') }}<br>
            {{ data_get($order->shipping_address, 'country_code') }}
        </address>
    </div>
</div>
