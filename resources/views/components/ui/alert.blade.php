@props(['type' => 'info', 'title' => null])

@php
    $styles = [
        'info' => 'border-accent-500/25 bg-accent-50 text-accent-700',
        'success' => 'border-emerald-300 bg-emerald-50 text-emerald-900',
        'warning' => 'border-amber-300 bg-amber-50 text-amber-900',
        'error' => 'border-red-300 bg-red-50 text-red-900',
    ];
    $role = in_array($type, ['error', 'warning'], true) ? 'alert' : 'status';
@endphp

<div role="{{ $role }}"
     {{ $attributes->merge(['class' => 'rounded-[--radius-card] border px-4 py-3 text-sm '.$styles[$type]]) }}>
    @if ($title)
        <p class="font-semibold">{{ $title }}</p>
    @endif
    <div @class(['mt-0.5' => $title])>{{ $slot }}</div>
</div>
