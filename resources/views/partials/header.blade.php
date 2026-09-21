@php
    $petTypes = \App\Models\PetType::active()->orderBy('position')->get();
    $headerNav = \App\Models\NavigationItem::location('header')->with('children')->get();
@endphp

<header class="sticky top-0 z-40 border-b border-ink-900/8 bg-cream-50/95 backdrop-blur"
        x-data="{ mobileOpen: false }">
    <div class="container-page">
        <div class="flex h-16 items-center justify-between gap-4 lg:h-20">

            <button type="button"
                    class="btn btn-ghost -ml-2 p-2 lg:hidden"
                    @click="mobileOpen = !mobileOpen"
                    :aria-expanded="mobileOpen"
                    aria-controls="mobile-nav"
                    aria-label="Toggle navigation menu">
                <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                    <path x-show="!mobileOpen" stroke-linecap="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/>
                    <path x-show="mobileOpen" x-cloak stroke-linecap="round" d="M6 18 18 6M6 6l12 12"/>
                </svg>
            </button>

            <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2.5">
                @if (branding()->imageUrl('logo_light'))
                    <img src="{{ branding()->imageUrl('logo_light') }}" alt="{{ branding('name') }}" class="h-8 w-auto lg:h-9">
                @else
                    <span class="font-display text-xl font-semibold text-ink-900 lg:text-2xl">{{ branding('name') }}</span>
                @endif
            </a>

            <nav class="hidden lg:flex lg:items-center lg:gap-1" aria-label="Main">
                @foreach ($petTypes as $petType)
                    <a href="{{ route('shop.pet-type', $petType) }}"
                       class="btn btn-ghost {{ request()->routeIs('shop.pet-type') && request()->route('petType')?->id === $petType->id ? 'bg-cream-200 text-ink-900' : '' }}">
                        {{ $petType->name }}
                    </a>
                @endforeach
                @foreach ($headerNav as $item)
                    <a href="{{ $item->url }}" class="btn btn-ghost">{{ $item->label }}</a>
                @endforeach
            </nav>

            <div class="flex items-center gap-1">
                <form action="{{ route('shop.index') }}" method="GET" class="hidden md:block" role="search">
                    <label for="header-search" class="sr-only">Search products</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-400"
                             fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/>
                        </svg>
                        <input id="header-search" type="search" name="q" value="{{ request('q') }}"
                               placeholder="Search"
                               class="field-input w-40 py-2 pl-9 text-sm lg:w-56">
                    </div>
                </form>

                <a href="{{ route('account.orders') }}" class="btn btn-ghost p-2" aria-label="Your account">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="8" r="3.5"/><path stroke-linecap="round" d="M4.5 20a7.5 7.5 0 0 1 15 0"/>
                    </svg>
                </a>

                @livewire('cart-button')
            </div>
        </div>
    </div>

    <div id="mobile-nav" x-show="mobileOpen" x-cloak x-collapse class="border-t border-ink-900/8 lg:hidden">
        <nav class="container-page space-y-1 py-4" aria-label="Mobile">
            <form action="{{ route('shop.index') }}" method="GET" class="mb-3" role="search">
                <label for="mobile-search" class="sr-only">Search products</label>
                <input id="mobile-search" type="search" name="q" value="{{ request('q') }}"
                       placeholder="Search products" class="field-input">
            </form>
            @foreach ($petTypes as $petType)
                <a href="{{ route('shop.pet-type', $petType) }}"
                   class="block rounded-lg px-3 py-2.5 text-ink-700 hover:bg-cream-200">{{ $petType->name }}</a>
            @endforeach
            @foreach ($headerNav as $item)
                <a href="{{ $item->url }}" class="block rounded-lg px-3 py-2.5 text-ink-700 hover:bg-cream-200">{{ $item->label }}</a>
            @endforeach
            <a href="{{ route('account.orders') }}" class="block rounded-lg px-3 py-2.5 text-ink-700 hover:bg-cream-200">Your orders</a>
        </nav>
    </div>
</header>
