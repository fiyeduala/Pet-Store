<x-layouts.storefront title="Your orders">
    <div class="container-page max-w-4xl py-10 lg:py-14">
        <h1 class="mb-8 text-3xl">Your orders</h1>

        @if ($orders->isEmpty())
            <x-ui.empty-state title="No orders yet" description="When you place an order it will appear here.">
                <a href="{{ route('shop.index') }}" class="btn btn-primary">Browse the shop</a>
            </x-ui.empty-state>
        @else
            <ul class="space-y-3">
                @foreach ($orders as $order)
                    <li class="card flex flex-wrap items-center justify-between gap-3 p-5">
                        <div>
                            <a href="{{ route('account.order', $order) }}" class="font-medium text-ink-900 hover:underline">
                                {{ $order->number }}
                            </a>
                            <p class="text-sm text-ink-500">
                                {{ $order->placed_at?->format('j M Y') }} ·
                                {{ $order->items->count() }} {{ Str::plural('item', $order->items->count()) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <x-store.order-status :order="$order" />
                            <span class="font-medium">{{ format_minor((int) $order->getRawOriginal('total_minor'), $order->currency) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="mt-8">{{ $orders->links() }}</div>
        @endif
    </div>
</x-layouts.storefront>
