<x-layouts.storefront title="Your account">
    <div class="container-page py-10 lg:py-14">
        <h1 class="mb-2 text-3xl">Hello, {{ auth()->user()->name }}</h1>
        <p class="mb-8 text-ink-500">Manage your orders, addresses and details.</p>

        @if (session('status'))
            <x-ui.alert type="success" class="mb-6">{{ session('status') }}</x-ui.alert>
        @endif

        <div class="grid gap-4 sm:grid-cols-3">
            <a href="{{ route('account.orders') }}" class="card p-5 transition-colors hover:border-ink-900/20">
                <h2 class="text-base">Orders</h2>
                <p class="mt-1 text-sm text-ink-500">View your order history and tracking.</p>
            </a>
            <a href="{{ route('account.addresses') }}" class="card p-5 transition-colors hover:border-ink-900/20">
                <h2 class="text-base">Addresses</h2>
                <p class="mt-1 text-sm text-ink-500">Manage your delivery addresses.</p>
            </a>
            <a href="{{ route('account.profile') }}" class="card p-5 transition-colors hover:border-ink-900/20">
                <h2 class="text-base">Profile</h2>
                <p class="mt-1 text-sm text-ink-500">Update your name, phone and preferences.</p>
            </a>
        </div>

        @if ($recentOrders->isNotEmpty())
            <section class="mt-10" aria-labelledby="recent-heading">
                <h2 id="recent-heading" class="mb-4 text-xl">Recent orders</h2>
                <ul class="divide-y divide-ink-900/8 border-y border-ink-900/8">
                    @foreach ($recentOrders as $order)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                            <div>
                                <a href="{{ route('account.order', $order) }}" class="font-medium text-ink-900 hover:underline">
                                    {{ $order->number }}
                                </a>
                                <p class="text-sm text-ink-500">{{ $order->placed_at?->format('j M Y') }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <x-store.order-status :order="$order" />
                                <span class="text-sm">{{ format_minor((int) $order->getRawOriginal('total_minor'), $order->currency) }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts.storefront>
