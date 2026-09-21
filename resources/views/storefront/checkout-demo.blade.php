<x-layouts.storefront title="Simulated payment">
    <div class="container-page max-w-lg py-12 lg:py-16">
        <x-ui.alert type="warning" title="This is a simulated payment">
            Nothing will be charged, no card or account is involved, and this order cannot be sent to a
            live supplier. It exists so the checkout flow can be demonstrated end to end.
        </x-ui.alert>

        <div class="card mt-6 p-5">
            <h1 class="text-xl">Order {{ $order->number }}</h1>
            <p class="mt-1 text-sm text-ink-500">
                Amount that would be charged:
                <span class="font-medium text-ink-900">{{ format_minor((int) $order->getRawOriginal('total_minor'), $order->currency) }}</span>
            </p>

            <form method="POST" action="{{ route('checkout.demo.settle', $order) }}" class="mt-5">
                @csrf
                <button type="submit" class="btn btn-primary w-full">Simulate a successful payment</button>
            </form>

            @if (session('error'))
                <x-ui.alert type="error" class="mt-4">{{ session('error') }}</x-ui.alert>
            @endif

            <p class="mt-4 text-xs text-ink-400">
                To simulate a decline instead, place an order whose number ends in <code>-DECLINE</code>.
            </p>
        </div>
    </div>
</x-layouts.storefront>
