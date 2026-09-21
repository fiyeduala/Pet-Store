<div>
    {{-- Off-canvas basket. Focus is trapped while open and returned on close. --}}
    <div x-data="{ open: @entangle('open') }"
         x-show="open"
         x-cloak
         class="relative z-50"
         role="dialog"
         aria-modal="true"
         aria-labelledby="cart-drawer-title"
         x-on:keydown.escape.window="open = false">

        <div x-show="open"
             x-transition:enter="transition-opacity ease-out duration-200"
             x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-in duration-150"
             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-ink-900/40"
             x-on:click="open = false"
             aria-hidden="true"></div>

        <div class="fixed inset-y-0 right-0 flex max-w-full pl-10">
            <div x-show="open"
                 x-transition:enter="transform transition ease-out duration-250"
                 x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transform transition ease-in duration-200"
                 x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                 x-trap.noscroll="open"
                 class="flex w-screen max-w-md flex-col bg-cream-50 shadow-xl">

                <div class="flex items-center justify-between border-b border-ink-900/8 px-5 py-4">
                    <h2 id="cart-drawer-title" class="text-lg">Your basket</h2>
                    <button type="button" class="btn btn-ghost p-2" x-on:click="open = false" aria-label="Close basket">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" d="M6 18 18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-5 py-4">
                    @if ($cart->items->isEmpty())
                        <x-ui.empty-state title="Your basket is empty"
                                          description="Browse the shop to find something your pet will actually use.">
                            <a href="{{ route('shop.index') }}" class="btn btn-primary" x-on:click="open = false">Start shopping</a>
                        </x-ui.empty-state>
                    @else
                        <ul class="divide-y divide-ink-900/8" wire:loading.class="opacity-60">
                            @foreach ($cart->items as $item)
                                <li class="flex gap-3.5 py-4" wire:key="cart-item-{{ $item->id }}">
                                    <div class="size-20 shrink-0 overflow-hidden rounded-lg bg-cream-200">
                                        @if ($image = $item->variant?->product?->primaryImage())
                                            <img src="{{ $image->url() }}" alt="" class="size-full object-cover" loading="lazy">
                                        @endif
                                    </div>

                                    <div class="flex min-w-0 flex-1 flex-col">
                                        <div class="flex justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-medium text-ink-900">
                                                    {{ $item->variant?->product?->name ?? data_get($item->snapshot, 'name') }}
                                                </p>
                                                @if ($item->variant?->option_summary)
                                                    <p class="text-xs text-ink-500">{{ $item->variant->option_summary }}</p>
                                                @endif
                                            </div>
                                            <x-ui.price :minor="(int) $item->getRawOriginal('unit_price_minor') * $item->quantity"
                                                        :currency="$item->currency" size="sm" />
                                        </div>

                                        <div class="mt-auto flex items-center justify-between pt-2">
                                            <div class="inline-flex items-center rounded-full border border-ink-900/15">
                                                <button type="button"
                                                        class="px-2.5 py-1 text-ink-600 hover:text-ink-900 disabled:opacity-40"
                                                        wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity - 1 }})"
                                                        aria-label="Decrease quantity">&minus;</button>
                                                <span class="min-w-8 text-center text-sm" aria-live="polite">{{ $item->quantity }}</span>
                                                <button type="button"
                                                        class="px-2.5 py-1 text-ink-600 hover:text-ink-900"
                                                        wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity + 1 }})"
                                                        aria-label="Increase quantity">+</button>
                                            </div>

                                            <button type="button" class="text-xs text-ink-400 underline hover:text-ink-700"
                                                    wire:click="remove({{ $item->id }})">Remove</button>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                @if ($cart->items->isNotEmpty())
                    <div class="space-y-3 border-t border-ink-900/8 bg-white px-5 py-4">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-ink-600">Subtotal</span>
                            <x-ui.price :minor="$totals->subtotal->minor" :currency="$cart->currency" />
                        </div>
                        {{-- No delivery claim is made before we know the destination. --}}
                        <p class="text-xs text-ink-400">{{ $totals->shippingNote }}</p>
                        <a href="{{ route('checkout.index') }}" class="btn btn-primary btn-lg w-full">Checkout</a>
                        <a href="{{ route('cart.index') }}" class="btn btn-secondary w-full" x-on:click="open = false">View full basket</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
