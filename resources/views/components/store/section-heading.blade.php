@props(['title', 'subtitle' => null, 'href' => null, 'linkLabel' => 'View all'])

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-wrap items-end justify-between gap-3']) }}>
    <div>
        <h2 class="text-2xl lg:text-3xl">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-1.5 max-w-xl text-sm text-ink-500">{{ $subtitle }}</p>
        @endif
    </div>
    @if ($href)
        <a href="{{ $href }}" class="btn btn-secondary text-sm">{{ $linkLabel }}</a>
    @endif
</div>
