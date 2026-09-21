@php
    $shopNav = \App\Models\NavigationItem::location('footer_shop')->get();
    $supportNav = \App\Models\NavigationItem::location('footer_support')->get();
    $companyNav = \App\Models\NavigationItem::location('footer_company')->get();
    $social = branding('social') ?: [];
@endphp

<footer class="mt-20 border-t border-ink-900/8 bg-cream-100">
    <div class="container-page py-12 lg:py-16">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">

            <div class="space-y-4">
                @if (branding()->imageUrl('logo_light'))
                    <img src="{{ branding()->imageUrl('logo_light') }}" alt="{{ branding('name') }}" class="h-8 w-auto">
                @else
                    <span class="font-display text-xl font-semibold text-ink-900">{{ branding('name') }}</span>
                @endif

                @if (branding('tagline'))
                    <p class="max-w-xs text-sm text-ink-500">{{ branding('tagline') }}</p>
                @endif

                @if ($social)
                    <div class="flex gap-2">
                        @foreach ($social as $label => $url)
                            @if ($url)
                                <a href="{{ $url }}" rel="noopener noreferrer" target="_blank"
                                   class="btn btn-secondary px-3 py-1.5 text-xs">{{ ucfirst($label) }}</a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <x-store.footer-column title="Shop" :items="$shopNav" />
            <x-store.footer-column title="Support" :items="$supportNav" />

            <div class="space-y-3">
                <h2 class="text-sm font-semibold tracking-wide text-ink-900 uppercase">Company</h2>
                <ul class="space-y-2 text-sm">
                    @foreach ($companyNav as $item)
                        <li><a href="{{ $item->url }}" class="text-ink-500 hover:text-ink-900">{{ $item->label }}</a></li>
                    @endforeach
                </ul>

                @if (branding('support_email') || branding('support_hours'))
                    <div class="space-y-1 pt-2 text-sm text-ink-500">
                        @if (branding('support_email'))
                            <p><a href="mailto:{{ branding('support_email') }}" class="hover:text-ink-900">{{ branding('support_email') }}</a></p>
                        @endif
                        @if (branding('support_phone'))
                            <p>{{ branding('support_phone') }}</p>
                        @endif
                        @if (branding('support_hours'))
                            <p class="text-xs">{{ branding('support_hours') }}</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <div class="mt-10 flex flex-col gap-3 border-t border-ink-900/8 pt-6 text-xs text-ink-400 sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ now()->year }} {{ branding('business_name') ?: branding('name') }}. All rights reserved.</p>
            @if (branding('business_address'))
                <p>{{ branding('business_address') }}</p>
            @endif
        </div>
    </div>
</footer>
