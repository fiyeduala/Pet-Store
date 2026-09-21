<?php

declare(strict_types=1);

namespace App\Domain\Checkout;

use App\Domain\Shipping\ShippingQuoteSet;
use App\Domain\Tax\TaxCalculator;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Discount;
use App\Models\Market;
use App\Models\ProductVariant;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CartService
{
    public function __construct(private readonly TaxCalculator $tax) {}

    public function forToken(?string $token, Market $market): Cart
    {
        if ($token !== null) {
            $cart = Cart::query()->with('items.variant.product')->where('token', $token)->first();

            if ($cart !== null) {
                return $cart;
            }
        }

        return Cart::create([
            'token' => (string) Str::uuid(),
            'market_id' => $market->id,
            'currency' => $market->currency,
            'user_id' => auth()->id(),
            'last_activity_at' => now(),
        ]);
    }

    public function add(Cart $cart, ProductVariant $variant, int $quantity = 1): CartItem
    {
        // The snapshot and stock check both read these relations, so load
        // them once up front rather than lazily mid-transaction.
        $variant->loadMissing(['product', 'warehouseStocks']);

        $price = $variant->effectivePriceMinor();

        if ($price === null) {
            throw new RuntimeException('This item is not currently available to buy.');
        }

        $available = $variant->availableStock();

        if ($available <= 0) {
            throw new RuntimeException(
                $variant->hasKnownStock()
                    ? 'This item is out of stock.'
                    : 'We cannot confirm availability for this item right now.'
            );
        }

        return DB::transaction(function () use ($cart, $variant, $quantity, $price, $available) {
            $item = $cart->items()->where('product_variant_id', $variant->id)->first();
            $newQuantity = min($available, ($item?->quantity ?? 0) + max(1, $quantity));

            if ($item !== null) {
                $item->forceFill(['quantity' => $newQuantity, 'unit_price_minor' => $price])->save();
            } else {
                $item = $cart->items()->create([
                    'product_variant_id' => $variant->id,
                    'quantity' => $newQuantity,
                    'unit_price_minor' => $price,
                    'currency' => $cart->currency,
                    'snapshot' => [
                        'sku' => $variant->sku,
                        'name' => $variant->product?->name,
                        'options' => $variant->option_summary,
                    ],
                ]);
            }

            // Any change invalidates a previously selected shipping quote.
            $cart->forceFill(['last_activity_at' => now(), 'selected_quote_group' => null])->save();

            return $item;
        });
    }

    public function updateQuantity(Cart $cart, CartItem $item, int $quantity): void
    {
        if ($quantity <= 0) {
            $this->remove($cart, $item);

            return;
        }

        $available = $item->variant?->availableStock() ?? 0;

        $item->forceFill(['quantity' => max(1, min($quantity, $available))])->save();
        $cart->forceFill(['last_activity_at' => now(), 'selected_quote_group' => null])->save();
    }

    public function remove(Cart $cart, CartItem $item): void
    {
        $item->delete();
        $cart->forceFill(['last_activity_at' => now(), 'selected_quote_group' => null])->save();
    }

    /**
     * @return array{applied: bool, message: string}
     */
    public function applyDiscount(Cart $cart, string $code, ?string $email = null): array
    {
        $discount = Discount::query()->whereRaw('LOWER(code) = ?', [Str::lower(trim($code))])->first();

        if ($discount === null || ! $discount->isCurrentlyValid()) {
            return ['applied' => false, 'message' => 'That code is not valid.'];
        }

        if ($discount->reachedLimitFor($email)) {
            return ['applied' => false, 'message' => 'That code has already been used on this account.'];
        }

        $minimum = $discount->getRawOriginal('min_subtotal_minor');

        if ($minimum !== null && $cart->subtotal()->minor < (int) $minimum) {
            return [
                'applied' => false,
                'message' => 'This code needs a subtotal of at least '.Money::ofMinor((int) $minimum, $cart->currency)->format().'.',
            ];
        }

        $cart->forceFill(['discount_id' => $discount->id])->save();

        return ['applied' => true, 'message' => 'Discount applied.'];
    }

    public function removeDiscount(Cart $cart): void
    {
        $cart->forceFill(['discount_id' => null])->save();
    }

    public function discountAmount(Cart $cart): Money
    {
        $discount = $cart->discount;
        $subtotal = $cart->subtotal();

        if ($discount === null || ! $discount->isCurrentlyValid()) {
            return Money::zero($cart->currency);
        }

        return match ($discount->type) {
            Discount::TYPE_PERCENTAGE => $subtotal->percentageOfBasisPoints(
                (int) round((float) $discount->percentage * 100)
            ),
            Discount::TYPE_FIXED => Money::min(
                Money::ofMinor((int) ($discount->getRawOriginal('amount_minor') ?? 0), $cart->currency),
                $subtotal
            ),
            default => Money::zero($cart->currency),
        };
    }

    /**
     * Compute totals. Shipping is only treated as final once a quote has
     * been selected for a real address.
     *
     * @param  array<string, mixed>|null  $destination
     */
    public function totals(Cart $cart, ?array $destination = null, ?ShippingQuoteSet $quotes = null, ?Money $selectedShipping = null): CartTotals
    {
        $currency = $cart->currency;
        $subtotal = $cart->subtotal();
        $discount = $this->discountAmount($cart);
        $discountedSubtotal = $subtotal->minus($discount);

        $freeShippingDiscount = $cart->discount?->type === \App\Models\Discount::TYPE_FREE_SHIPPING;

        $shipping = match (true) {
            $freeShippingDiscount => Money::zero($currency),
            $selectedShipping !== null => $selectedShipping,
            $quotes !== null && ! $quotes->isBlocked() && ! $quotes->isEmpty()
                => Money::ofMinor($quotes->cheapestTotalMinor(), $currency),
            default => Money::zero($currency),
        };

        $taxResult = null;
        $tax = Money::zero($currency);

        if ($destination !== null) {
            $taxResult = $this->tax->calculate($cart->market, $destination, $discountedSubtotal, $shipping);
            $tax = $taxResult->amount;
        }

        return new CartTotals(
            subtotal: $subtotal,
            discount: $discount,
            shipping: $shipping,
            tax: $tax,
            total: $discountedSubtotal->plus($shipping)->plus($tax),
            taxResult: $taxResult,
            shippingNote: $destination === null
                ? 'Shipping and tax are calculated once you enter a delivery address.'
                : null,
            shippingEstimated: $selectedShipping === null,
        );
    }
}
