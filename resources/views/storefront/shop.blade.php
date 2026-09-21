<x-layouts.storefront :title="$heading.' — '.branding('name')" :description="$subheading">
    <div class="container-page py-8 lg:py-12">

        <header class="mb-8">
            <h1 class="text-3xl lg:text-4xl">{{ $heading }}</h1>
            @if ($subheading)
                <p class="mt-2 max-w-2xl text-ink-500">{{ $subheading }}</p>
            @endif
        </header>

        <div class="grid gap-8 lg:grid-cols-[260px_minmax(0,1fr)]">

            {{-- Filters. Every control writes to the query string so the view
                 is linkable and survives refresh and back navigation. --}}
            <aside x-data="{ open: false }">
                <button type="button" class="btn btn-secondary mb-4 w-full lg:hidden"
                        @click="open = !open" :aria-expanded="open" aria-controls="filters">
                    Filters
                    @if (count($activeFilters) > 0)
                        <span class="badge bg-accent-100 text-accent-700">{{ count($activeFilters) }}</span>
                    @endif
                </button>

                <form id="filters" method="GET" x-show="open || window.innerWidth >= 1024"
                      x-cloak class="space-y-6 lg:!block">

                    @if (request('q'))
                        <input type="hidden" name="q" value="{{ request('q') }}">
                    @endif
                    <input type="hidden" name="sort" value="{{ $activeSort }}">

                    <fieldset>
                        <legend class="field-label">Pet</legend>
                        <div class="space-y-1.5">
                            @foreach ($filterOptions['petTypes'] as $petType)
                                <label class="flex items-center gap-2.5 text-sm">
                                    <input type="checkbox" name="pet[]" value="{{ $petType->slug }}"
                                           @checked(in_array($petType->slug, (array) request('pet', []), true))
                                           class="size-4 rounded accent-[--brand-accent]">
                                    {{ $petType->name }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="field-label">Category</legend>
                        <div class="space-y-1.5">
                            @foreach ($filterOptions['categories'] as $category)
                                <label class="flex items-center gap-2.5 text-sm">
                                    <input type="checkbox" name="category[]" value="{{ $category->slug }}"
                                           @checked(in_array($category->slug, (array) request('category', []), true))
                                           class="size-4 rounded accent-[--brand-accent]">
                                    {{ $category->name }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="field-label">Price</legend>
                        <div class="flex items-center gap-2">
                            <label for="min_price" class="sr-only">Minimum price</label>
                            <input id="min_price" type="number" min="0" step="1" name="min_price"
                                   value="{{ request('min_price') }}" placeholder="Min" class="field-input py-2 text-sm">
                            <span class="text-ink-400" aria-hidden="true">–</span>
                            <label for="max_price" class="sr-only">Maximum price</label>
                            <input id="max_price" type="number" min="0" step="1" name="max_price"
                                   value="{{ request('max_price') }}" placeholder="Max" class="field-input py-2 text-sm">
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="field-label">Availability</legend>
                        <label class="flex items-center gap-2.5 text-sm">
                            <input type="checkbox" name="availability" value="in_stock"
                                   @checked(request('availability') === 'in_stock')
                                   class="size-4 rounded accent-[--brand-accent]">
                            In stock now
                        </label>
                        <p class="mt-1 text-xs text-ink-400">
                            Items whose availability our supplier has not confirmed are excluded by this filter.
                        </p>
                    </fieldset>

                    @foreach ($filterOptions['attributes'] as $attribute)
                        @if ($attribute->values->isNotEmpty())
                            <fieldset>
                                <legend class="field-label">{{ $attribute->name }}</legend>
                                <div class="space-y-1.5">
                                    @foreach ($attribute->values as $value)
                                        <label class="flex items-center gap-2.5 text-sm">
                                            <input type="checkbox" name="attr_{{ $attribute->code }}[]" value="{{ $value->value }}"
                                                   @checked(in_array($value->value, (array) request('attr_'.$attribute->code, []), true))
                                                   class="size-4 rounded accent-[--brand-accent]">
                                            {{ $value->displayLabel() }}
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif
                    @endforeach

                    <div class="flex gap-2">
                        <button type="submit" class="btn btn-primary flex-1">Apply</button>
                        @if (count($activeFilters) > 0)
                            <a href="{{ url()->current() }}" class="btn btn-secondary">Clear</a>
                        @endif
                    </div>
                </form>
            </aside>

            <div>
                <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-ink-500" role="status">
                        {{ $products->total() }} {{ Str::plural('product', $products->total()) }}
                    </p>

                    <form method="GET" class="flex items-center gap-2">
                        @foreach (request()->except(['sort', 'page']) as $key => $value)
                            @foreach ((array) $value as $v)
                                <input type="hidden" name="{{ $key }}{{ is_array($value) ? '[]' : '' }}" value="{{ $v }}">
                            @endforeach
                        @endforeach
                        <label for="sort" class="text-sm text-ink-500">Sort</label>
                        <select id="sort" name="sort" class="field-input py-2 text-sm" onchange="this.form.submit()">
                            @foreach ($sorts as $value => $label)
                                <option value="{{ $value }}" @selected($activeSort === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <noscript><button type="submit" class="btn btn-secondary text-sm">Go</button></noscript>
                    </form>
                </div>

                @if ($products->isEmpty())
                    <x-ui.empty-state title="No products match those filters"
                                      description="Try removing a filter or widening the price range.">
                        <a href="{{ url()->current() }}" class="btn btn-secondary">Clear filters</a>
                    </x-ui.empty-state>
                @else
                    <div class="grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach ($products as $product)
                            <x-store.product-card :product="$product" />
                        @endforeach
                    </div>

                    <div class="mt-10">{{ $products->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.storefront>
