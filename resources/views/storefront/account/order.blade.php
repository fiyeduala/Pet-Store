<x-layouts.storefront :title="'Order '.$order->number">
    <div class="container-page max-w-3xl py-10 lg:py-14">
        <a href="{{ route('account.orders') }}" class="btn btn-ghost mb-4 -ml-3 text-sm">&larr; All orders</a>
        <h1 class="mb-8 text-3xl">Order {{ $order->number }}</h1>
        @include('storefront.order-detail-partial', ['order' => $order, 'showActions' => true])
    </div>
</x-layouts.storefront>
