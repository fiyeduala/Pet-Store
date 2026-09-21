<x-layouts.storefront title="Your orders">
    <div class="container-page max-w-lg py-12 lg:py-16 text-center">
        <h1 class="text-3xl">Your orders</h1>
        <p class="mt-2 text-ink-500">
            You can look up a guest order without an account, or sign in to see your full history.
        </p>
        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <a href="{{ route('orders.track') }}" class="btn btn-primary">Track a guest order</a>
            <a href="{{ route('login') }}" class="btn btn-secondary">Sign in</a>
        </div>
    </div>
</x-layouts.storefront>
