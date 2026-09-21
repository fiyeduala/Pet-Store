<div class="container-page py-8 lg:py-12">
    <h1 class="mb-8 text-3xl">Your basket</h1>

    @if ($cart->isEmpty())
        <x-ui.empty-state title="Your basket is empty"
                          description="Nothing here yet. Have a look around the shop.">
            <a href="{{ route('shop.index') }}" class="btn btn-primary">Browse the shop</a>
        </x-ui.empty-state>
    @else
        <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_340px]">

            <div>
                <ul class="divide-y divide-ink-900/8 border-y border-ink-900/8">
                    @foreach ($cart->items as $item)
                        <li class="flex gap-4 py-5" wire:key="line-{{ $item->id }}">
                            <a href="{{ $item->variant?->product ? route('product.show', $item->variant->product) : '#' }}"
                               class="size-24 shrink-0 overflow-hidden rounded-lg bg-cream-200 sm:size-28">
                                @if ($image = $item->variant?->product?->primaryImage())
                                    <img src="{{ $image->url() }}" alt="{{ $image->altText() }}" class="size-full object-cover" loading="lazy">
                                @endif
                            </a>

                            <div class="flex min-w-0 flex-1 flex-col">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <h2 class="text-base font-medium text-ink-900">
                                            @if ($item->variant?->product)
                                                <a href="{{ route('product.show', $item->variant->product) }}" class="hover:underline">
                                                    {{ $item->variant->product->name }}
                                                </a>
                                            @else
                                                {{ data_get($item->snapshot, 'name', 'Item') }}
                                            @endif
                                        </h2>
                                        @if ($item->variant?->option_summary)
                                            <p class="text-sm text-ink-500">{{ $item->variant->option_summary }}</p>
                                        @endif
                                        <div class="mt-1.5">
                                            <x-ui.stock-badge :variant="$item->variant" />
                                        </div>
                                    </div>
                                    <x-ui.price :minor="(int) $item->getRawOriginal('unit_price_minor') * $item->quantity"
                                                :currency="$item->currency" />
                                </div>

                                <div class="mt-auto flex items-center justify-between gap-3 pt-3">
                                    <div class="inline-flex items-center rounded-full border border-ink-900/15">
                                        <button type="button" class="px-3 py-1.5 text-ink-600 hover:text-ink-900"
                                                wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity - 1 }})"
                                                aria-label="Decrease quantity of {{ $item->variant?->product?->name }}">&minus;</button>
                                        <span class="min-w-9 text-center text-sm">{{ $item->quantity }}</span>
                                        <button type="button" class="px-3 py-1.5 text-ink-600 hover:text-ink-900"
                                                wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity + 1 }})"
                                                aria-label="Increase quantity of {{ $item->variant?->product?->name }}">+</button>
                                    </div>
                                    <button type="button" class="text-sm text-ink-400 underline hover:text-ink-700"
                                            wire:click="remove({{ $item->id }})">Remove</button>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <a href="{{ route('shop.index') }}" class="btn btn-ghost mt-6 -ml-3">&larr; Continue shopping</a>
            </div>

            <aside class="lg:sticky lg:top-24 lg:self-start">
                <div class="card space-y-4 p-5">
                    <h2 class="text-lg">Summary</h2>

                    <div>
                        <label for="cart-discount" class="field-label">Discount code</label>
                        <div class="flex gap-2">
                            <input id="cart-discount" wire:model="discountCode" class="field-input py-2 text-sm">
                            <button type="button" class="btn btn-secondary text-sm" wire:click="applyDiscount">Apply</button>
                        </div>
                        @if ($discountMessage)
                            <p class="mt-1.5 text-xs {{ $cart->discount ? 'text-emerald-700' : 'text-red-700' }}" role="status">
                                {{ $discountMessage }}
                            </p>
                        @endif
                        @if ($cart->discount)
                            <button type="button" class="mt-1 text-xs text-ink-400 underline" wire:click="removeDiscount">
                                Remove {{ $cart->discount->code }}
                            </button>
                        @endif
                    </div>

                    <dl class="space-y-2 border-t border-ink-900/8 pt-4 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-ink-600">Subtotal</dt>
                            <dd>{{ format_minor($totals->subtotal->minor, $cart->currency) }}</dd>
                        </div>
                        @if (! $totals->discount->isZero())
                            <div class="flex justify-between text-emerald-700">
                                <dt>Discount</dt>
                                <dd>&minus;{{ format_minor($totals->discount->minor, $cart->currency) }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between border-t border-ink-900/8 pt-2 text-base font-semibold text-ink-900">
                            <dt>Estimated total</dt>
                            <dd>{{ format_minor($totals->subtotal->minus($totals->discount)->minor, $cart->currency) }}</dd>
                        </div>
                    </dl>

                    {{-- No shipping or tax figure is claimed before we know
                         the destination, and no delivery promise is made. --}}
                    <p class="text-xs text-ink-400">{{ $totals->shippingNote }}</p>

                    <a href="{{ route('checkout.index') }}" class="btn btn-primary btn-lg w-full">Checkout</a>
                </div>
            </aside>
        </div>
    @endif
</div>
