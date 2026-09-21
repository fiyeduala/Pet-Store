<x-layouts.storefront title="Frequently asked questions">
    <div class="container-page max-w-3xl py-10 lg:py-16">
        <h1 class="text-3xl lg:text-4xl">Frequently asked questions</h1>

        @forelse ($groups as $category => $faqs)
            <section class="mt-10" aria-labelledby="faq-{{ Str::slug($category) }}">
                <h2 id="faq-{{ Str::slug($category) }}" class="text-xl">{{ $category }}</h2>
                <div class="mt-4 divide-y divide-ink-900/8 border-y border-ink-900/8">
                    @foreach ($faqs as $faq)
                        <details class="group py-4" name="faq-{{ Str::slug($category) }}">
                            <summary class="flex cursor-pointer items-center justify-between gap-3 font-medium text-ink-900">
                                {{ $faq->question }}
                                <svg class="size-5 shrink-0 text-ink-400 transition-transform group-open:rotate-180 motion-reduce:transition-none"
                                     fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" d="m6 9 6 6 6-6"/>
                                </svg>
                            </summary>
                            <div class="prose-store mt-3 text-sm">{!! nl2br(e($faq->answer)) !!}</div>
                        </details>
                    @endforeach
                </div>
            </section>
        @empty
            <x-ui.empty-state class="mt-10" title="No questions published yet" />
        @endforelse
    </div>
</x-layouts.storefront>
