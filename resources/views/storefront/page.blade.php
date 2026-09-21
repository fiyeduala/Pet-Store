<x-layouts.storefront :title="($page->seo_title ?: $page->title).' — '.branding('name')"
                      :description="$page->seo_description ?: $page->excerpt">
    <div class="container-page max-w-3xl py-10 lg:py-16">
        <h1 class="text-3xl lg:text-4xl">{{ $page->title }}</h1>
        @if ($page->excerpt)
            <p class="mt-3 text-lg text-ink-500">{{ $page->excerpt }}</p>
        @endif

        @if ($page->is_policy && $page->requires_owner_review)
            {{-- Draft policy text is labelled for the reader, not quietly
                 presented as a settled legal position. --}}
            <x-ui.alert type="warning" class="mt-6" title="Draft — under review">
                This policy is still being finalised. If anything here matters to your purchase, please
                contact us and we will confirm it in writing.
            </x-ui.alert>
        @endif

        <div class="prose-store mt-8">{!! nl2br(e($page->body)) !!}</div>

        <p class="mt-10 text-xs text-ink-400">Last updated {{ $page->updated_at->format('j F Y') }}.</p>
    </div>
</x-layouts.storefront>
