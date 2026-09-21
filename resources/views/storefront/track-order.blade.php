<x-layouts.storefront :title="'Order '.$order->number">
    <div class="container-page max-w-3xl py-10 lg:py-14">
        <h1 class="mb-8 text-3xl">Your order</h1>
        @include('storefront.order-detail-partial', ['order' => $order])
    </div>
</x-layouts.storefront>
