<x-layouts.storefront title="Your session expired">
    <div class="container-page max-w-md py-20 text-center">
        <p class="text-sm font-medium tracking-wide text-ink-400">Error 419</p>
        <h1 class="mt-2 text-3xl">Your session expired</h1>
        <p class="mt-3 text-ink-500">For your security we ended that session. Please try again.</p>
        <div class="mt-7 flex flex-wrap justify-center gap-3">
            <a href="{{ route('home') }}" class="btn btn-primary">Back to the shop</a>
            <a href="{{ route('contact') }}" class="btn btn-secondary">Contact us</a>
        </div>
    </div>
</x-layouts.storefront>
