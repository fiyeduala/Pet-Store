<x-layouts.storefront :title="($product->seo_title ?: $product->name).' — '.branding('name')"
                      :description="$product->seo_description ?: $product->subtitle"
                      :canonical="route('product.show', $product)"
                      og-type="product"
                      :og-image="$product->primaryImage()?->url()">

    @push('head')
        @php
            $ldVariant = $product->cheapestVariant();
            $ldProduct = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $product->name,
                'description' => $product->subtitle ?: Str::limit(strip_tags((string) $product->description), 300),
                'sku' => $ldVariant?->sku,
                'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand] : null,
                'offers' => $ldVariant ? [
                    '@type' => 'Offer',
                    'url' => route('product.show', $product),
                    'priceCurrency' => $ldVariant->currency,
                    'price' => number_format($ldVariant->effectivePriceMinor() / 100, 2, '.', ''),
                    // Only what we can actually evidence. Unknown availability
                    // is reported as out of stock rather than claimed as in stock.
                    'availability' => $product->hasAnyKnownStock()
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                ] : null,
            ], fn ($value) => $value !== null);
        @endphp
        {{-- Product structured data. Only facts we actually hold are emitted:
             no invented ratings, certifications or availability. --}}
        <script type="application/ld+json">
            {!! json_encode($ldProduct, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
        </script>
    @endpush

    <div class="container-page py-6 lg:py-10">

        <nav aria-label="Breadcrumb" class="mb-6 text-sm text-ink-500">
            <ol class="flex flex-wrap items-center gap-1.5">
                <li><a href="{{ route('home') }}" class="hover:text-ink-900">Home</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="{{ route('shop.index') }}" class="hover:text-ink-900">Shop</a></li>
                @if ($category = $product->categories->first())
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('shop.category', $category) }}" class="hover:text-ink-900">{{ $category->name }}</a></li>
                @endif
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="text-ink-900">{{ $product->name }}</li>
            </ol>
        </nav>

        <div class="grid gap-8 lg:grid-cols-2 lg:gap-12">

            {{-- Gallery --}}
            <div x-data="{ active: 0 }">
                <div class="overflow-hidden rounded-[--radius-card] bg-cream-100">
                    @forelse ($product->media as $index => $image)
                        <img x-show="active === {{ $index }}"
                             src="{{ $image->url() }}"
                             alt="{{ $image->altText($product->name) }}"
                             class="aspect-square size-full object-cover"
                             @if ($index === 0) fetchpriority="high" @else loading="lazy" @endif>
                    @empty
                        <div class="flex aspect-square items-center justify-center text-ink-300">
                            <svg class="size-12" fill="none" stroke="currentColor" stroke-width="1.25" viewBox="0 0 24 24" aria-hidden="true">
                                <rect x="3" y="3" width="18" height="18" rx="2"/><path d="m3 15 4.5-4.5 5 5M14 13l2.5-2.5L21 15"/>
                            </svg>
                        </div>
                    @endforelse
                </div>

                @if ($product->media->count() > 1)
                    <div class="mt-3 flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Product images">
                        @foreach ($product->media as $index => $image)
                            <button type="button" role="tab"
                                    :aria-selected="active === {{ $index }}"
                                    @click="active = {{ $index }}"
                                    class="size-16 shrink-0 overflow-hidden rounded-lg border-2 transition-colors"
                                    :class="active === {{ $index }} ? 'border-[--brand-accent]' : 'border-transparent'"
                                    aria-label="View image {{ $index + 1 }}">
                                <img src="{{ $image->url() }}" alt="" class="size-full object-cover" loading="lazy">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Details --}}
            <div class="space-y-7">
                <div>
                    @if ($product->brand)
                        <p class="text-sm uppercase tracking-wide text-ink-400">{{ $product->brand }}</p>
                    @endif
                    <h1 class="mt-1 text-3xl lg:text-4xl">{{ $product->name }}</h1>
                    @if ($product->subtitle)
                        <p class="mt-2 text-ink-500">{{ $product->subtitle }}</p>
                    @endif
                </div>

                @livewire('product-purchase-panel', ['product' => $product])

                @if ($product->description)
                    <div class="prose-store border-t border-ink-900/8 pt-6">
                        {!! nl2br(e($product->description)) !!}
                    </div>
                @endif

                {{-- Specifications, only rendered when we actually hold the data. --}}
                @php
                    $variant = $product->activeVariantsLoaded()->firstWhere('id', $product->default_variant_id)
                        ?? $product->activeVariantsLoaded()->first();
                    $specs = collect([
                        'Suitable for' => $product->suitable_for,
                        'Materials' => $product->materials,
                        'Care' => $product->care_instructions,
                        'Dimensions' => $variant && $variant->length_mm
                            ? sprintf('%.1f × %.1f × %.1f cm',
                                $variant->length_mm / 10, $variant->width_mm / 10, $variant->height_mm / 10)
                            : null,
                        'Weight' => $variant?->weight_grams ? number_format($variant->weight_grams).' g' : null,
                    ])->filter();

                    foreach ($product->attributeValues as $av) {
                        $specs[$av->attribute->name] = $av->display().($av->attribute->unit ? ' '.$av->attribute->unit : '');
                    }
                @endphp

                @if ($specs->isNotEmpty())
                    <div class="border-t border-ink-900/8 pt-6">
                        <h2 class="mb-3 text-lg">Details</h2>
                        <dl class="divide-y divide-ink-900/8 text-sm">
                            @foreach ($specs as $label => $value)
                                <div class="grid grid-cols-3 gap-3 py-2.5">
                                    <dt class="text-ink-500">{{ $label }}</dt>
                                    <dd class="col-span-2 text-ink-900">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif

                @if ($product->petTypes->isNotEmpty())
                    <div class="flex flex-wrap gap-2">
                        @foreach ($product->petTypes as $petType)
                            <a href="{{ route('shop.pet-type', $petType) }}" class="badge bg-cream-200 text-ink-600 hover:bg-cream-300">
                                For {{ Str::lower($petType->name) }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Reviews. Only genuine, published reviews reach this list. --}}
        @if ($reviews->isNotEmpty())
            <section class="mt-16 border-t border-ink-900/8 pt-10" aria-labelledby="reviews-heading">
                <h2 id="reviews-heading" class="mb-6 text-2xl">Customer reviews</h2>
                <ul class="grid gap-5 sm:grid-cols-2">
                    @foreach ($reviews as $review)
                        <li class="card p-5">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-ink-900">{{ $review->author_name }}</span>
                                @if ($review->is_verified_purchase)
                                    <span class="badge bg-accent-50 text-accent-700">Verified purchase</span>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-ink-400" aria-label="{{ $review->rating }} out of 5">
                                {{ str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating) }}
                            </p>
                            @if ($review->title)
                                <p class="mt-2 font-medium text-ink-900">{{ $review->title }}</p>
                            @endif
                            <p class="mt-1 text-sm text-ink-600">{{ $review->body }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($related->isNotEmpty())
            <section class="mt-16 border-t border-ink-900/8 pt-10" aria-labelledby="related-heading">
                <h2 id="related-heading" class="mb-6 text-2xl">You might also like</h2>
                <div class="grid grid-cols-2 gap-x-4 gap-y-8 lg:grid-cols-4">
                    @foreach ($related as $item)
                        <x-store.product-card :product="$item" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.storefront>
