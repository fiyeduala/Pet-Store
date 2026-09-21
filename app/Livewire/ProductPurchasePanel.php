<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Checkout\CartService;
use App\Domain\Shipping\ShippingQuoteService;
use App\Domain\Shipping\WarehouseSelector;
use App\Livewire\Concerns\InteractsWithCart;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RuntimeException;

/**
 * Variant selection, quantity, ZIP estimator and add-to-basket.
 */
class ProductPurchasePanel extends Component
{
    use InteractsWithCart;

    public Product $product;

    public ?int $selectedVariantId = null;

    public int $quantity = 1;

    #[Validate('nullable|string|max:12')]
    public string $zip = '';

    /** @var array<int, array<string, mixed>>|null */
    public ?array $estimates = null;

    public ?string $estimateError = null;

    public bool $estimating = false;

    public function mount(Product $product): void
    {
        $this->product = $product;
        $this->selectedVariantId = $this->initialVariantId($product);
    }

    /**
     * Prefer the merchandised default, but only while it can actually be
     * bought. Landing a shopper on an out-of-stock option when another one
     * is available reads as "this product is unavailable" and loses the
     * sale for no reason.
     */
    private function initialVariantId(Product $product): ?int
    {
        $variants = $product->activeVariantsLoaded();

        $default = $variants->firstWhere('id', $product->default_variant_id);

        if ($default?->isPurchasable()) {
            return $default->id;
        }

        return $variants->first(fn (ProductVariant $v) => $v->isPurchasable())?->id
            ?? $default?->id
            ?? $variants->first()?->id;
    }

    public function selectVariant(int $variantId): void
    {
        $this->selectedVariantId = $variantId;
        // A different variant can ship from a different warehouse, so the
        // previous estimate no longer applies.
        $this->estimates = null;
        $this->estimateError = null;
        $this->quantity = 1;
    }

    public function getVariantProperty(): ?ProductVariant
    {
        return $this->product->activeVariantsLoaded()->firstWhere('id', $this->selectedVariantId);
    }

    public function getMaxQuantityProperty(): int
    {
        return max(0, min(20, $this->variant?->availableStock() ?? 0));
    }

    public function updatedQuantity(): void
    {
        $this->quantity = max(1, min($this->quantity, max(1, $this->maxQuantity)));
    }

    public function addToCart(): void
    {
        $variant = $this->variant;

        if ($variant === null) {
            $this->addError('variant', 'Choose an option first.');

            return;
        }

        try {
            app(CartService::class)->add($this->currentCart(), $variant, $this->quantity);
        } catch (RuntimeException $e) {
            $this->addError('variant', $e->getMessage());

            return;
        }

        $this->dispatch('cart-updated');
        $this->dispatch('open-cart-drawer');
    }

    /**
     * ZIP-code delivery estimator.
     *
     * Quotes are cached briefly per (variant, quantity, ZIP) so repeated
     * lookups do not hammer the supplier's 1 req/sec limit.
     */
    public function estimate(): void
    {
        $this->validate();
        $this->estimateError = null;
        $this->estimates = null;

        $variant = $this->variant;

        if ($variant === null) {
            $this->estimateError = 'Choose an option first.';

            return;
        }

        $zip = trim($this->zip);

        if (! preg_match('/^\d{5}(-\d{4})?$/', $zip)) {
            $this->estimateError = 'Enter a 5-digit US ZIP code.';

            return;
        }

        $market = $this->currentMarket();
        $cacheKey = sprintf('estimate.%d.%d.%s', $variant->id, $this->quantity, $zip);

        $result = Cache::remember(
            $cacheKey,
            now()->addMinutes((int) config('petstore.shipping.estimator_ttl_minutes', 30)),
            function () use ($variant, $market, $zip): array {
                $plan = app(WarehouseSelector::class)->plan(
                    collect([['variant' => $variant, 'quantity' => $this->quantity]]),
                    $market,
                    allowOverseas: $market->allow_overseas_fulfilment,
                );

                $set = app(ShippingQuoteService::class)->quote(
                    $plan,
                    $market,
                    ['country_code' => 'US', 'postal_code' => $zip, 'state' => null, 'city' => null],
                    $variant->effectivePrice() ?? \App\Support\Money\Money::zero($market->currency),
                );

                if ($set->isBlocked()) {
                    return ['error' => $set->blockedReason];
                }

                if ($set->isEmpty()) {
                    return ['error' => 'No delivery service is available to that ZIP code for this item.'];
                }

                return [
                    'parcels' => $set->parcelCount,
                    'options' => $set->quotes->map(fn ($q) => [
                        'service' => $q->service_name,
                        'amount' => format_minor((int) $q->getRawOriginal('amount_minor'), $q->currency),
                        // The estimate keeps its unit, type and provenance.
                        'estimate' => $q->estimateLabel(),
                        'source' => $q->estimate_source,
                    ])->all(),
                ];
            }
        );

        if (isset($result['error'])) {
            $this->estimateError = $result['error'];

            return;
        }

        $this->estimates = $result;
    }

    public function render(): View
    {
        return view('livewire.product-purchase-panel');
    }
}
