@props(['variant'])

@php
    /*
     * Three distinct states, never collapsed into two:
     *   in stock          — a warehouse reported a usable number
     *   out of stock      — a warehouse reported zero
     *   availability unknown — nobody reported anything
     */
    $known = $variant?->hasKnownStock() ?? false;
    $available = $variant?->availableStock() ?? 0;
@endphp

@if (! $known)
    <span class="badge bg-cream-200 text-ink-600" title="Our supplier has not reported a stock figure for this item.">
        <span class="size-1.5 rounded-full bg-ink-400" aria-hidden="true"></span>
        Availability unknown
    </span>
@elseif ($available <= 0)
    <span class="badge bg-cream-200 text-ink-500">
        <span class="size-1.5 rounded-full bg-ink-400" aria-hidden="true"></span>
        Out of stock
    </span>
@elseif ($available <= 5)
    <span class="badge bg-amber-100 text-amber-900">
        <span class="size-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
        Only {{ $available }} left
    </span>
@else
    <span class="badge bg-accent-50 text-accent-700">
        <span class="size-1.5 rounded-full bg-accent-500" aria-hidden="true"></span>
        In stock
    </span>
@endif
