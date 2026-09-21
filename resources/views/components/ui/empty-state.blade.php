@props(['title', 'description' => null, 'icon' => 'box'])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-[--radius-card] border border-dashed border-ink-900/15 bg-white/60 px-6 py-16 text-center']) }}>
    <div class="mb-4 flex size-12 items-center justify-center rounded-full bg-cream-200 text-ink-400" aria-hidden="true">
        <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 7.5 12 3.75l8.25 3.75M3.75 7.5v9L12 20.25l8.25-3.75v-9M3.75 7.5 12 11.25m0 0 8.25-3.75M12 11.25v9"/>
        </svg>
    </div>
    <h2 class="text-lg font-semibold text-ink-900">{{ $title }}</h2>
    @if ($description)
        <p class="mt-1.5 max-w-sm text-sm text-ink-500">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
