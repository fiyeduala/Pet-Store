@props(['order'])

@php
    /*
     * Customer-facing status. Deliberately reports only what the customer
     * can act on; the five internal machines stay in admin.
     */
    $map = [
        'new' => ['Awaiting payment', 'bg-cream-200 text-ink-600'],
        'payment_failed' => ['Payment failed', 'bg-red-100 text-red-900'],
        'paid' => ['Paid — preparing your order', 'bg-accent-50 text-accent-700'],
        'awaiting_approval' => ['Paid — preparing your order', 'bg-accent-50 text-accent-700'],
        'approved' => ['Preparing your order', 'bg-accent-50 text-accent-700'],
        'submitted_to_supplier' => ['Preparing your order', 'bg-accent-50 text-accent-700'],
        'processing' => ['Being packed', 'bg-accent-50 text-accent-700'],
        'reconciling' => ['Preparing your order', 'bg-accent-50 text-accent-700'],
        'on_hold' => ['On hold — we will be in touch', 'bg-amber-100 text-amber-900'],
        'partially_shipped' => ['Partly on its way', 'bg-accent-50 text-accent-700'],
        'shipped' => ['On its way', 'bg-accent-50 text-accent-700'],
        'partially_delivered' => ['Partly delivered', 'bg-emerald-50 text-emerald-900'],
        'delivered' => ['Delivered', 'bg-emerald-100 text-emerald-900'],
        'cancelled' => ['Cancelled', 'bg-cream-200 text-ink-500'],
        'refunded' => ['Refunded', 'bg-cream-200 text-ink-500'],
    ];

    [$label, $classes] = $map[$order->lifecycle_status] ?? ['Processing', 'bg-cream-200 text-ink-600'];
@endphp

<span {{ $attributes->merge(['class' => 'badge '.$classes]) }}>{{ $label }}</span>
