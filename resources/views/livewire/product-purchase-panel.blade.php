<div class="space-y-6">
    @php
        $variant = $this->variant;
        $max = $this->maxQuantity;
    @endphp

    <div class="flex flex-wrap items-center gap-3">
        <x-ui.price :minor="$variant?->effectivePriceMinor()"
                    :currency="$variant?->currency ?? 'USD'"
                    :compare-at-minor="$variant?->displayCompareAtMinor()"
                    size="xl" />
        <x-ui.stock-badge :variant="$variant" />
    </div>

    @if ($this->product->activeVariantsLoaded()->count() > 1)
        <fieldset>
            <legend class="field-label">Choose an option</legend>
            <div class="flex flex-wrap gap-2" role="radiogroup">
                @foreach ($this->product->activeVariantsLoaded() as $option)
                    @php $purchasable = $option->isPurchasable(); @endphp
                    <button type="button"
                            role="radio"
                            aria-checked="{{ $selectedVariantId === $option->id ? 'true' : 'false' }}"
                            wire:click="selectVariant({{ $option->id }})"
                            wire:key="variant-{{ $option->id }}"
                            @class([
                                'rounded-full border px-4 py-2 text-sm transition-colors',
                                'border-transparent bg-ink-900 text-white' => $selectedVariantId === $option->id,
                                'border-ink-900/15 bg-white text-ink-700 hover:border-ink-900/35' => $selectedVariantId !== $option->id,
                                'opacity-50' => ! $purchasable,
                            ])>
                        {{ $option->optionLabel() }}
                        @unless ($purchasable)
                            <span class="ml-1 text-xs">({{ $option->hasKnownStock() ? 'out of stock' : 'unconfirmed' }})</span>
                        @endunless
                    </button>
                @endforeach
            </div>
        </fieldset>
    @endif

    @error('variant')
        <x-ui.alert type="error">{{ $message }}</x-ui.alert>
    @enderror

    <div class="flex flex-wrap items-end gap-3">
        <div class="w-28">
            <label for="quantity" class="field-label">Quantity</label>
            <input id="quantity" type="number" min="1" max="{{ max(1, $max) }}"
                   wire:model.live.debounce.400ms="quantity"
                   class="field-input"
                   @disabled($max < 1)>
        </div>

        <button type="button"
                class="btn btn-primary btn-lg flex-1 sm:flex-none sm:min-w-52"
                wire:click="addToCart"
                wire:loading.attr="disabled"
                @disabled($max < 1)>
            <span wire:loading.remove wire:target="addToCart">
                {{ $max < 1 ? 'Unavailable' : 'Add to basket' }}
            </span>
            <span wire:loading wire:target="addToCart" class="inline-flex items-center gap-2">
                <svg class="size-4 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                    <path class="opacity-80" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v3a5 5 0 0 0-5 5H4z"/>
                </svg>
                Adding…
            </span>
        </button>
    </div>

    @if ($max < 1 && $variant)
        <x-ui.alert type="info">
            @if ($variant->hasKnownStock())
                This option is out of stock. Choose another option, or check back soon.
            @else
                {{-- Unknown is reported as unknown, never as "in stock". --}}
                Our supplier has not confirmed availability for this option, so we cannot accept an order for it yet.
            @endif
        </x-ui.alert>
    @endif

    {{-- ZIP estimator. Nothing about delivery is claimed until a destination
         is known, and whatever the carrier actually said is what is shown. --}}
    <div class="card space-y-3 p-4">
        <h2 class="text-sm font-semibold text-ink-900">Delivery to your address</h2>

        @unless ($estimates)
            <p class="text-sm text-ink-500">
                {{ app(\App\Domain\Shipping\DeliveryEstimatePresenter::class)->preDestinationMessage() }}
            </p>
        @endunless

        <form wire:submit="estimate" class="flex gap-2">
            <div class="flex-1">
                <label for="zip" class="sr-only">ZIP code</label>
                <input id="zip" type="text" inputmode="numeric" autocomplete="postal-code"
                       placeholder="ZIP code" wire:model="zip" class="field-input"
                       aria-invalid="{{ $estimateError ? 'true' : 'false' }}"
                       aria-describedby="zip-feedback">
            </div>
            <button type="submit" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="estimate">
                <span wire:loading.remove wire:target="estimate">Check</span>
                <span wire:loading wire:target="estimate">Checking…</span>
            </button>
        </form>

        <div id="zip-feedback">
            @if ($estimateError)
                <p class="field-error" role="alert">{{ $estimateError }}</p>
            @endif

            <div wire:loading wire:target="estimate" class="space-y-2">
                <div class="skeleton h-4 w-3/4"></div>
                <div class="skeleton h-4 w-1/2"></div>
            </div>

            @if ($estimates)
                <div wire:loading.remove wire:target="estimate" class="space-y-2">
                    @if (($estimates['parcels'] ?? 1) > 1)
                        {{-- Split shipments are disclosed before purchase. --}}
                        <x-ui.alert type="info">
                            This order would arrive in {{ $estimates['parcels'] }} separate parcels, each tracked on its own.
                        </x-ui.alert>
                    @endif

                    <ul class="divide-y divide-ink-900/8 text-sm">
                        @foreach ($estimates['options'] as $option)
                            <li class="flex items-start justify-between gap-3 py-2">
                                <div>
                                    <p class="font-medium text-ink-900">{{ $option['service'] }}</p>
                                    <p class="text-ink-500">
                                        {{ $option['estimate'] }}
                                        @if ($option['source'] === 'owner_policy')
                                            <span class="text-xs text-ink-400">(our estimate, not the carrier's)</span>
                                        @endif
                                    </p>
                                </div>
                                <span class="whitespace-nowrap font-medium text-ink-900">{{ $option['amount'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="text-xs text-ink-400">
                        Estimates are provided by the carrier at the time of quoting and are confirmed again at checkout.
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
