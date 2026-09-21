<x-layouts.storefront title="Order confirmed">
    <div class="container-page max-w-3xl py-10 lg:py-16">

        <div class="mb-8 text-center">
            <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-700" aria-hidden="true">
                <svg class="size-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/>
                </svg>
            </div>
            <h1 class="text-3xl">Thank you — your order is confirmed</h1>
            <p class="mt-2 text-ink-500">
                We have emailed a receipt to {{ $order->email }}. Your order number is
                <span class="font-medium text-ink-900">{{ $order->number }}</span>.
            </p>
        </div>

        {{-- Set expectations honestly: paid is not the same as shipped. --}}
        <x-ui.alert type="info" class="mb-8" title="What happens next">
            We review every order before sending it to our fulfilment partner. You will get another email
            with tracking as soon as each parcel is dispatched.
        </x-ui.alert>

        @include('storefront.order-detail-partial', ['order' => $order])

        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ route('shop.index') }}" class="btn btn-primary">Continue shopping</a>
            <a href="{{ route('orders.track') }}" class="btn btn-secondary">Track this order later</a>
        </div>
    </div>
</x-layouts.storefront>
