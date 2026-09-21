@props(['quote' => null, 'shipment' => null, 'showSource' => false])

@php
    $presenter = app(\App\Domain\Shipping\DeliveryEstimatePresenter::class);
    $text = match (true) {
        $quote !== null => $presenter->forQuote($quote),
        $shipment !== null => $presenter->forShipment($shipment),
        default => $presenter->preDestinationMessage(),
    };
    $source = $quote?->estimate_source;
@endphp

<span {{ $attributes->merge(['class' => 'text-sm text-ink-500']) }}>
    {{ $text }}
    @if ($showSource && $source === 'owner_policy')
        <span class="text-xs text-ink-400">(our own estimate, not the carrier's)</span>
    @endif
</span>
