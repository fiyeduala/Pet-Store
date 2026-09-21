@props([
    'minor' => null,
    'currency' => 'USD',
    'compareAtMinor' => null,
    'size' => 'base',
    'prefix' => null,
])

@php
    $sizes = [
        'sm' => 'text-sm',
        'base' => 'text-base',
        'lg' => 'text-xl',
        'xl' => 'text-2xl',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-baseline gap-2']) }}>
    @if ($minor === null)
        <span class="{{ $sizes[$size] }} text-ink-400">Price unavailable</span>
    @else
        @if ($prefix)
            <span class="text-xs text-ink-400">{{ $prefix }}</span>
        @endif
        <span class="{{ $sizes[$size] }} font-semibold text-ink-900">{{ format_minor($minor, $currency) }}</span>
        @if ($compareAtMinor !== null && $compareAtMinor > $minor)
            <span class="text-sm text-ink-400 line-through">{{ format_minor($compareAtMinor, $currency) }}</span>
        @endif
    @endif
</span>
