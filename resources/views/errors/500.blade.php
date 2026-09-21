<x-layouts.storefront title="Something went wrong">
    <div class="container-page max-w-md py-20 text-center">
        <p class="text-sm font-medium tracking-wide text-ink-400">Error 500</p>
        <h1 class="mt-2 text-3xl">Something went wrong</h1>
        <p class="mt-3 text-ink-500">We hit an unexpected problem. Nothing has been charged.</p>
        <div class="mt-7 flex flex-wrap justify-center gap-3">
            <a href="{{ route('home') }}" class="btn btn-primary">Back to the shop</a>
            <a href="{{ route('contact') }}" class="btn btn-secondary">Contact us</a>
        </div>
    </div>
</x-layouts.storefront>
