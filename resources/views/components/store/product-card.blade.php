@props(['product'])

@php
    $variant = $product->cheapestVariant() ?? $product->variants->first();
    $image = $product->primaryImage();
    $priceMinor = $variant?->effectivePriceMinor();
    $compareMinor = $variant?->displayCompareAtMinor();
    $multiplePrices = $product->activeVariantsLoaded()->count() > 1;
@endphp

<article {{ $attributes->merge(['class' => 'group flex h-full flex-col']) }}>
    <a href="{{ route('product.show', $product) }}" class="block focus-visible:outline-offset-4">
        <div class="relative aspect-square overflow-hidden rounded-[--radius-card] bg-cream-100">
            @if ($image)
                <img src="{{ $image->url() }}"
                     alt="{{ $image->altText($product->name) }}"
                     loading="lazy" decoding="async"
                     class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03] motion-reduce:transition-none motion-reduce:group-hover:scale-100">
            @else
                <div class="flex size-full items-center justify-center text-ink-300" aria-hidden="true">
                    <svg class="size-10" fill="none" stroke="currentColor" stroke-width="1.25" viewBox="0 0 24 24">
                        <rect x="3" y="3" width="18" height="18" rx="2"/><path d="m3 15 4.5-4.5 5 5M14 13l2.5-2.5L21 15"/>
                    </svg>
                </div>
            @endif

            @if ($variant?->saleIsActive())
                <span class="badge absolute left-3 top-3 bg-white/95 text-ink-900 shadow-sm">On sale</span>
            @endif
        </div>
    </a>

    <div class="mt-3.5 flex flex-1 flex-col gap-1.5">
        <h3 class="text-sm font-medium leading-snug text-ink-900">
            <a href="{{ route('product.show', $product) }}" class="hover:underline">{{ $product->name }}</a>
        </h3>

        @if ($product->subtitle)
            <p class="line-clamp-2 text-xs text-ink-500">{{ $product->subtitle }}</p>
        @endif

        <div class="mt-auto flex items-center justify-between gap-2 pt-1.5">
            <x-ui.price :minor="$priceMinor"
                        :currency="$variant?->currency ?? 'USD'"
                        :compare-at-minor="$compareMinor"
                        :prefix="$multiplePrices ? 'from' : null"
                        size="sm" />
            <x-ui.stock-badge :variant="$variant" />
        </div>
    </div>
</article>
