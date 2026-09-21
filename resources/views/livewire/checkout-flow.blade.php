<div class="container-page py-8 lg:py-12">
    <h1 class="mb-8 text-3xl">Checkout</h1>

    @if ($cart->isEmpty())
        <x-ui.empty-state title="Your basket is empty" description="Add something before checking out.">
            <a href="{{ route('shop.index') }}" class="btn btn-primary">Browse the shop</a>
        </x-ui.empty-state>
    @else
        <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_380px]">

            <div class="space-y-8">
                @if ($checkoutError)
                    <x-ui.alert type="error" title="We could not complete that">{{ $checkoutError }}</x-ui.alert>
                @endif

                @if ($guestDisabled)
                    <x-ui.alert type="info" title="Sign in required">
                        Guest checkout is currently switched off.
                        <a href="{{ route('login') }}" class="underline">Sign in</a> or
                        <a href="{{ route('register') }}" class="underline">create an account</a> to continue.
                    </x-ui.alert>
                @elseif (! auth()->check())
                    <x-ui.alert type="info">
                        Checking out as a guest. Already have an account?
                        <a href="{{ route('login') }}" class="underline">Sign in</a> for faster checkout.
                    </x-ui.alert>
                @endif

                <form wire:submit="placeOrder" class="space-y-8">

                    {{-- Contact --}}
                    <section aria-labelledby="contact-heading" class="card p-5 lg:p-6">
                        <h2 id="contact-heading" class="mb-4 text-lg">Contact</h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="email" class="field-label">Email <span aria-hidden="true">*</span></label>
                                <input id="email" type="email" autocomplete="email" wire:model.blur="email"
                                       class="field-input" aria-invalid="@error('email')true @else false @enderror"
                                       aria-describedby="email-error" required>
                                <p id="email-error">@error('email') <span class="field-error">{{ $message }}</span> @enderror</p>
                            </div>
                            <div class="sm:col-span-2">
                                <label for="phone" class="field-label">Phone <span aria-hidden="true">*</span></label>
                                {{-- Collected because the carrier needs it for delivery, not for marketing. --}}
                                <input id="phone" type="tel" autocomplete="tel" wire:model.blur="phone" class="field-input" required>
                                <p class="mt-1 text-xs text-ink-400">Used only so the carrier can contact you about delivery.</p>
                                @error('phone') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </section>

                    {{-- Shipping address --}}
                    <section aria-labelledby="address-heading" class="card p-5 lg:p-6">
                        <h2 id="address-heading" class="mb-4 text-lg">Delivery address</h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="firstName" class="field-label">First name <span aria-hidden="true">*</span></label>
                                <input id="firstName" autocomplete="given-name" wire:model.blur="firstName" class="field-input" required>
                                @error('firstName') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="lastName" class="field-label">Last name <span aria-hidden="true">*</span></label>
                                <input id="lastName" autocomplete="family-name" wire:model.blur="lastName" class="field-input" required>
                                @error('lastName') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label for="line1" class="field-label">Address <span aria-hidden="true">*</span></label>
                                <input id="line1" autocomplete="address-line1" wire:model.blur="line1" class="field-input" required>
                                @error('line1') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label for="line2" class="field-label">Apartment, suite, etc. <span class="text-ink-400">(optional)</span></label>
                                <input id="line2" autocomplete="address-line2" wire:model.blur="line2" class="field-input">
                            </div>
                            <div>
                                <label for="city" class="field-label">City <span aria-hidden="true">*</span></label>
                                <input id="city" autocomplete="address-level2" wire:model.blur="city" class="field-input" required>
                                @error('city') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="state" class="field-label">State <span aria-hidden="true">*</span></label>
                                <input id="state" autocomplete="address-level1" wire:model.blur="state" class="field-input"
                                       maxlength="2" placeholder="CA" required>
                                @error('state') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="postalCode" class="field-label">ZIP code <span aria-hidden="true">*</span></label>
                                <input id="postalCode" inputmode="numeric" autocomplete="postal-code"
                                       wire:model.blur="postalCode" class="field-input" required>
                                @error('postalCode') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="country" class="field-label">Country</label>
                                {{-- Only the enabled market is offered. --}}
                                <input id="country" value="United States" class="field-input bg-cream-100" disabled>
                            </div>
                        </div>
                    </section>

                    {{-- Delivery --}}
                    <section aria-labelledby="delivery-heading" class="card p-5 lg:p-6">
                        <h2 id="delivery-heading" class="mb-4 text-lg">Delivery</h2>

                        @if ($quotes === null)
                            <p class="text-sm text-ink-500">
                                Enter your address above and we will fetch the services available to you.
                            </p>
                        @elseif ($quotes->isBlocked())
                            <x-ui.alert type="warning" title="We cannot ship to this address">
                                {{ $quotes->blockedReason }}
                            </x-ui.alert>
                        @else
                            @if ($quotes->isSplit)
                                <x-ui.alert type="info" class="mb-4" title="This order ships in {{ $quotes->parcelCount }} parcels">
                                    Items are stocked in different warehouses, so they travel separately and each gets its own tracking.
                                </x-ui.alert>
                            @endif

                            <div class="space-y-6">
                                @foreach ($quotes->byParcel() as $parcelIndex => $parcelQuotes)
                                    <fieldset>
                                        @if ($quotes->isSplit)
                                            <legend class="field-label">Parcel {{ $parcelIndex + 1 }}</legend>
                                        @else
                                            <legend class="sr-only">Delivery service</legend>
                                        @endif

                                        <div class="space-y-2">
                                            @foreach ($parcelQuotes as $quote)
                                                <label wire:key="quote-{{ $quote->id }}"
                                                       @class([
                                                           'flex cursor-pointer items-start gap-3 rounded-lg border p-3.5 transition-colors',
                                                           'border-[--brand-accent] bg-accent-50' => ($selectedQuotes[$parcelIndex] ?? null) === $quote->id,
                                                           'border-ink-900/15 hover:border-ink-900/30' => ($selectedQuotes[$parcelIndex] ?? null) !== $quote->id,
                                                       ])>
                                                    <input type="radio"
                                                           name="parcel-{{ $parcelIndex }}"
                                                           value="{{ $quote->id }}"
                                                           wire:click="selectQuote({{ $parcelIndex }}, {{ $quote->id }})"
                                                           @checked(($selectedQuotes[$parcelIndex] ?? null) === $quote->id)
                                                           class="mt-1 size-4 accent-[--brand-accent]">
                                                    <span class="flex-1">
                                                        <span class="block text-sm font-medium text-ink-900">{{ $quote->service_name }}</span>
                                                        {{-- Whatever the carrier actually said, with its unit intact. --}}
                                                        <span class="block text-sm text-ink-500">{{ $quote->estimateLabel() }}</span>
                                                    </span>
                                                    <span class="text-sm font-medium text-ink-900">
                                                        {{ (int) $quote->getRawOriginal('amount_minor') === 0
                                                            ? 'Free'
                                                            : format_minor((int) $quote->getRawOriginal('amount_minor'), $quote->currency) }}
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                @endforeach
                            </div>
                        @endif
                    </section>

                    {{-- Payment --}}
                    <section aria-labelledby="payment-heading" class="card p-5 lg:p-6">
                        <h2 id="payment-heading" class="mb-4 text-lg">Payment</h2>

                        @if ($gateways->isEmpty())
                            {{-- Live checkout fails clearly rather than falling back. --}}
                            <x-ui.alert type="error" title="Checkout is unavailable">
                                {{ $noGatewayReason }}
                                @if (branding('support_email'))
                                    Please contact us at {{ branding('support_email') }}.
                                @endif
                            </x-ui.alert>
                        @else
                            <div class="space-y-2">
                                @foreach ($gateways as $gateway)
                                    <label wire:key="gw-{{ $gateway->id }}"
                                           @class([
                                               'flex cursor-pointer items-start gap-3 rounded-lg border p-3.5',
                                               'border-[--brand-accent] bg-accent-50' => $selectedGateway === $gateway->code,
                                               'border-ink-900/15 hover:border-ink-900/30' => $selectedGateway !== $gateway->code,
                                           ])>
                                        <input type="radio" name="gateway" value="{{ $gateway->code }}"
                                               wire:model.live="selectedGateway"
                                               class="mt-1 size-4 accent-[--brand-accent]">
                                        <span class="flex-1">
                                            <span class="block text-sm font-medium text-ink-900">{{ $gateway->name }}</span>
                                            @if ($gateway->mode !== 'live')
                                                {{-- Simulated payments are labelled unmistakably. --}}
                                                <span class="badge mt-1 bg-amber-100 text-amber-900">
                                                    Simulated — no money will be taken
                                                </span>
                                            @endif
                                        </span>
                                        {{-- The charge currency is stated before paying. --}}
                                        <span class="text-xs text-ink-400">Charged in {{ $cart->currency }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </section>

                    <div>
                        <label for="note" class="field-label">Order note <span class="text-ink-400">(optional)</span></label>
                        <textarea id="note" rows="3" wire:model.blur="note" class="field-input"
                                  placeholder="Anything we should know about this delivery?"></textarea>
                    </div>

                    <button type="submit"
                            class="btn btn-primary btn-lg w-full"
                            wire:loading.attr="disabled"
                            wire:target="placeOrder"
                            @disabled($gateways->isEmpty() || $guestDisabled)>
                        <span wire:loading.remove wire:target="placeOrder">
                            Pay {{ format_minor($totals->total->minor, $cart->currency) }}
                        </span>
                        <span wire:loading wire:target="placeOrder">Starting payment…</span>
                    </button>

                    <p class="text-center text-xs text-ink-400">
                        You will be taken to the payment provider to complete your purchase.
                    </p>
                </form>
            </div>

            {{-- Summary --}}
            <aside class="lg:sticky lg:top-24 lg:self-start">
                <div class="card p-5">
                    <h2 class="mb-4 text-lg">Order summary</h2>

                    <ul class="mb-4 divide-y divide-ink-900/8">
                        @foreach ($cart->items as $item)
                            <li class="flex gap-3 py-3">
                                <div class="size-14 shrink-0 overflow-hidden rounded-lg bg-cream-200">
                                    @if ($image = $item->variant?->product?->primaryImage())
                                        <img src="{{ $image->url() }}" alt="" class="size-full object-cover" loading="lazy">
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1 text-sm">
                                    <p class="truncate font-medium text-ink-900">{{ $item->variant?->product?->name }}</p>
                                    <p class="text-ink-500">
                                        {{ $item->variant?->option_summary }}
                                        <span class="text-ink-400">&times;{{ $item->quantity }}</span>
                                    </p>
                                </div>
                                <span class="text-sm">{{ format_minor((int) $item->getRawOriginal('unit_price_minor') * $item->quantity, $item->currency) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="space-y-2 border-t border-ink-900/8 pt-4">
                        <div class="flex gap-2">
                            <label for="discount" class="sr-only">Discount code</label>
                            <input id="discount" wire:model="discountCode" placeholder="Discount code" class="field-input py-2 text-sm">
                            <button type="button" class="btn btn-secondary text-sm" wire:click="applyDiscount">Apply</button>
                        </div>
                        @if ($discountMessage)
                            <p class="text-xs {{ $cart->discount ? 'text-emerald-700' : 'text-red-700' }}">{{ $discountMessage }}</p>
                        @endif
                    </div>

                    <dl class="mt-4 space-y-2 border-t border-ink-900/8 pt-4 text-sm">
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
                        <div class="flex justify-between">
                            <dt class="text-ink-600">Delivery</dt>
                            <dd>
                                @if ($quotes === null)
                                    <span class="text-ink-400">Enter address</span>
                                @elseif ($totals->shipping->isZero())
                                    Free
                                @else
                                    {{ format_minor($totals->shipping->minor, $cart->currency) }}
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-ink-600">Tax</dt>
                            <dd>
                                @if ($totals->taxResult === null)
                                    <span class="text-ink-400">Enter address</span>
                                @elseif ($totals->tax->isZero())
                                    {{-- Zero tax is stated as zero, with no claim about why. --}}
                                    <span title="{{ $totals->taxResult->note }}">{{ format_minor(0, $cart->currency) }}</span>
                                @else
                                    {{ format_minor($totals->tax->minor, $cart->currency) }}
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between border-t border-ink-900/8 pt-2 text-base font-semibold text-ink-900">
                            <dt>Total</dt>
                            <dd>{{ format_minor($totals->total->minor, $cart->currency) }}</dd>
                        </div>
                    </dl>
                </div>
            </aside>
        </div>
    @endif
</div>
