<x-layouts.storefront :title="branding('seo_title') ?: branding('name').' — '.branding('tagline')"
                      :description="branding('seo_description')">

    @foreach ($sections as $section)
        @switch($section->type)

            @case('hero')
                <section class="border-b border-ink-900/8 bg-cream-100">
                    <div class="container-page grid items-center gap-8 py-12 lg:grid-cols-2 lg:gap-12 lg:py-20">
                        <div class="max-w-xl">
                            <h1 class="text-4xl leading-[1.1] lg:text-5xl">{{ $section->title }}</h1>
                            @if ($section->subtitle)
                                <p class="mt-4 text-lg text-ink-500">{{ $section->subtitle }}</p>
                            @endif
                            <div class="mt-7 flex flex-wrap gap-3">
                                @if ($section->cta_label)
                                    <a href="{{ $section->cta_url ?: route('shop.index') }}" class="btn btn-primary btn-lg">
                                        {{ $section->cta_label }}
                                    </a>
                                @endif
                                @foreach ($petTypes->take(2) as $petType)
                                    <a href="{{ route('shop.pet-type', $petType) }}" class="btn btn-secondary btn-lg">
                                        Shop {{ Str::lower($petType->name) }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                        @if ($section->image_path)
                            <div class="overflow-hidden rounded-[--radius-card] bg-cream-200">
                                <img src="{{ Storage::disk('public')->url($section->image_path) }}"
                                     alt="{{ $section->image_alt }}"
                                     class="aspect-[4/3] size-full object-cover"
                                     fetchpriority="high">
                            </div>
                        @endif
                    </div>
                </section>
                @break

            @case('pet_entry')
                <section class="container-page py-12 lg:py-16">
                    <x-store.section-heading :title="$section->title ?: 'Shop by pet'" :subtitle="$section->subtitle" />
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-{{ min(4, max(2, $petTypes->count())) }}">
                        @foreach ($petTypes as $petType)
                            <a href="{{ route('shop.pet-type', $petType) }}"
                               class="group relative overflow-hidden rounded-[--radius-card] bg-cream-200">
                                <div class="aspect-[4/3]">
                                    @if ($petType->image_path)
                                        <img src="{{ Storage::disk('public')->url($petType->image_path) }}"
                                             alt="" loading="lazy"
                                             class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03] motion-reduce:transition-none motion-reduce:group-hover:scale-100">
                                    @endif
                                </div>
                                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-ink-900/70 to-transparent p-4">
                                    <h3 class="text-lg text-white">{{ $petType->name }}</h3>
                                    @if ($petType->tagline)
                                        <p class="text-sm text-white/80">{{ $petType->tagline }}</p>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
                @break

            @case('collection_row')
                @if ($section->collection)
                    <section class="container-page py-12 lg:py-16">
                        <x-store.section-heading :title="$section->title ?: $section->collection->title"
                                                 :subtitle="$section->subtitle ?: $section->collection->subtitle"
                                                 :href="route('shop.collection', $section->collection)" />
                        <div class="grid grid-cols-2 gap-x-4 gap-y-8 lg:grid-cols-4">
                            @foreach ($section->collection->products->take(4) as $product)
                                <x-store.product-card :product="$product" />
                            @endforeach
                        </div>
                    </section>
                @endif
                @break

            @case('featured_products')
                @if ($featured->isNotEmpty())
                    <section class="container-page py-12 lg:py-16">
                        <x-store.section-heading :title="$section->title ?: 'Featured'"
                                                 :subtitle="$section->subtitle"
                                                 :href="route('shop.index')" />
                        <div class="grid grid-cols-2 gap-x-4 gap-y-8 lg:grid-cols-4">
                            @foreach ($featured->take(4) as $product)
                                <x-store.product-card :product="$product" />
                            @endforeach
                        </div>
                    </section>
                @endif
                @break

            @case('editorial')
                <section class="border-y border-ink-900/8 bg-cream-100">
                    <div class="container-page grid items-center gap-8 py-12 lg:grid-cols-2 lg:py-16">
                        @if ($section->image_path)
                            <div class="overflow-hidden rounded-[--radius-card] bg-cream-200 lg:order-last">
                                <img src="{{ Storage::disk('public')->url($section->image_path) }}"
                                     alt="{{ $section->image_alt }}" loading="lazy"
                                     class="aspect-[3/2] size-full object-cover">
                            </div>
                        @endif
                        <div class="max-w-lg">
                            <h2 class="text-2xl lg:text-3xl">{{ $section->title }}</h2>
                            @if ($section->body)
                                <div class="prose-store mt-4">{!! nl2br(e($section->body)) !!}</div>
                            @endif
                            @if ($section->cta_label)
                                <a href="{{ $section->cta_url }}" class="btn btn-secondary mt-5">{{ $section->cta_label }}</a>
                            @endif
                        </div>
                    </div>
                </section>
                @break

            @case('info_columns')
                <section class="container-page py-12 lg:py-16">
                    <div class="grid gap-6 sm:grid-cols-3">
                        @foreach ((array) $section->setting('columns', []) as $column)
                            <div class="card p-5">
                                <h3 class="text-base">{{ $column['title'] ?? '' }}</h3>
                                <p class="mt-1.5 text-sm text-ink-500">{{ $column['body'] ?? '' }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
                @break

        @endswitch
    @endforeach

    @if ($sections->isEmpty())
        <section class="container-page py-20">
            <x-ui.empty-state title="No home sections configured yet"
                              description="Add sections in Admin → Content → Home sections to build this page." />
        </section>
    @endif
</x-layouts.storefront>
