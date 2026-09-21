<?php

declare(strict_types=1);

namespace App\Domain\Checkout;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Settings\Branding;
use App\Domain\Shipping\ShippingQuoteService;
use App\Domain\Shipping\WarehouseSelector;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\ShippingQuote;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a validated cart into an order.
 *
 * Everything that could later change — prices, branding, business details,
 * the delivery promise — is snapshotted onto the order at this moment, so
 * the record of what the customer was actually told survives later edits.
 */
class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly ShippingQuoteService $shipping,
        private readonly WarehouseSelector $warehouses,
        private readonly OrderStateMachine $states,
        private readonly Branding $branding,
    ) {}

    /**
     * @param  array<string, mixed>  $customer  name, email, phone
     * @param  array<string, mixed>  $shippingAddress
     * @param  array<int, int>  $selectedQuoteIds  one quote per parcel
     */
    public function place(
        Cart $cart,
        array $customer,
        array $shippingAddress,
        array $selectedQuoteIds,
        ?array $billingAddress = null,
    ): Order {
        if ($cart->isEmpty()) {
            throw new RuntimeException('Your basket is empty.');
        }

        $quotes = ShippingQuote::query()->whereIn('id', $selectedQuoteIds)->get();

        if ($quotes->isEmpty()) {
            throw new RuntimeException('Choose a delivery service before placing the order.');
        }

        // An expired quote must be re-fetched, not silently honoured or
        // silently repriced.
        if ($quotes->contains(fn (ShippingQuote $q) => $q->isExpired())) {
            throw new QuoteExpiredException('Your delivery quote has expired. Please review the updated options.');
        }

        $market = $cart->market;
        $currency = $market->currency;
        $shippingMinor = (int) $quotes->sum(fn (ShippingQuote $q) => (int) $q->getRawOriginal('amount_minor'));

        $totals = $this->carts->totals(
            $cart,
            $shippingAddress,
            null,
            Money::ofMinor($shippingMinor, $currency),
        );

        return DB::transaction(function () use ($cart, $customer, $shippingAddress, $billingAddress, $totals, $quotes, $market, $currency) {
            $order = Order::create([
                'number' => $this->generateNumber(),
                'market_id' => $market->id,
                'currency' => $currency,
                'user_id' => $cart->user_id ?? auth()->id(),
                'is_guest' => $cart->user_id === null && ! auth()->check(),
                'email' => $customer['email'],
                'phone' => $customer['phone'] ?? null,

                'payment_state' => Order::PAYMENT_PENDING,
                'approval_state' => Order::APPROVAL_NOT_REQUIRED,
                'supplier_order_state' => Order::SUPPLIER_NOT_SUBMITTED,
                'supplier_payment_state' => Order::SUPPLIER_PAY_NOT_PAID,
                'shipment_state' => Order::SHIPMENT_NONE,
                'lifecycle_status' => 'new',

                'subtotal_minor' => $totals->subtotal->minor,
                'discount_minor' => $totals->discount->minor,
                'shipping_minor' => $totals->shipping->minor,
                'tax_minor' => $totals->tax->minor,
                'total_minor' => $totals->total->minor,

                'supplier_shipping_cost_minor' => (int) $quotes->sum(fn (ShippingQuote $q) => (int) ($q->getRawOriginal('supplier_cost_minor') ?? 0)),

                'billing_address' => $billingAddress ?: $shippingAddress,
                'shipping_address' => $shippingAddress,
                // Frozen copies: later branding changes must not rewrite history.
                'brand_snapshot' => $this->branding->snapshot(),
                'business_snapshot' => $this->branding->businessSnapshot(),
                'totals_breakdown' => $totals->toArray() + [
                    'delivery_promise' => $quotes->map(fn (ShippingQuote $q) => [
                        'service' => $q->service_name,
                        'estimate' => $q->estimateLabel(),
                        'source' => $q->estimate_source,
                        'amount_minor' => (int) $q->getRawOriginal('amount_minor'),
                    ])->all(),
                ],

                'fulfilment_mode' => $market->allow_overseas_fulfilment ? 'overseas_allowed' : 'domestic_only',
                'packaging_choice' => (string) settings('packaging.default_choice', 'standard'),
                'is_demo' => false,
                'placed_at' => now(),
                'customer_note' => $customer['note'] ?? null,
            ]);

            $this->attachAccessToken($order);
            $this->createItems($order, $cart, $quotes);
            $this->recordDiscountRedemption($order, $cart, $totals);

            // Tie the chosen quotes to this order for later comparison.
            ShippingQuote::whereIn('id', $quotes->pluck('id'))->update([
                'line_allocation->order_id' => $order->id,
            ]);

            $cart->items()->delete();
            $cart->forceFill(['discount_id' => null, 'selected_quote_group' => null])->save();

            return $order->refresh();
        });
    }

    /**
     * Guest access uses a high-entropy token; only its hash is stored.
     * The order number alone never grants access.
     */
    public function attachAccessToken(Order $order): string
    {
        $token = bin2hex(random_bytes((int) config('petstore.guest_access.token_bytes', 32)));

        $order->forceFill([
            'access_token_hash' => hash('sha256', $token),
            'access_token_expires_at' => now()->addDays((int) config('petstore.guest_access.token_lifetime_days', 90)),
        ])->save();

        return $token;
    }

    private function createItems(Order $order, Cart $cart, $quotes): void
    {
        // Map each variant to the warehouse whose parcel actually carries it.
        $warehouseByVariant = [];

        foreach ($quotes as $quote) {
            foreach ((array) data_get($quote->line_allocation, 'lines', []) as $line) {
                $warehouseByVariant[(int) $line['variant_id']] = $quote->warehouse_id;
            }
        }

        $merchandiseCost = 0;

        foreach ($cart->items as $item) {
            /** @var CartItem $item */
            $variant = $item->variant;
            $unit = (int) $item->getRawOriginal('unit_price_minor');
            $lineSubtotal = $unit * $item->quantity;
            $cost = $variant?->getRawOriginal('supplier_cost_minor');

            if ($cost !== null) {
                $merchandiseCost += (int) $cost * $item->quantity;
            }

            $order->items()->create([
                'product_variant_id' => $variant?->id,
                'sku' => $variant?->sku ?? data_get($item->snapshot, 'sku', 'UNKNOWN'),
                'name' => $variant?->product?->name ?? data_get($item->snapshot, 'name', 'Item'),
                'option_summary' => $variant?->option_summary,
                'quantity' => $item->quantity,
                'unit_price_minor' => $unit,
                'line_subtotal_minor' => $lineSubtotal,
                'line_discount_minor' => 0,
                'line_tax_minor' => 0,
                'currency' => $order->currency,
                'supplier_variant_id' => $variant?->supplier_variant_id,
                'supplier_cost_minor' => $cost,
                'supplier_cost_currency' => $variant?->supplier_cost_currency,
                'warehouse_id' => $warehouseByVariant[$variant?->id] ?? null,
                'snapshot' => [
                    'product_slug' => $variant?->product?->slug,
                    'image' => $variant?->product?->primaryImage()?->path,
                    'materials' => $variant?->product?->materials,
                ],
            ]);
        }

        // Allocate the order-level discount and tax across lines so per-line
        // refunds stay exact and the parts always sum back to the whole.
        $this->allocateAcrossLines($order);

        $order->forceFill(['merchandise_cost_minor' => $merchandiseCost ?: null])->save();
    }

    private function allocateAcrossLines(Order $order): void
    {
        $items = $order->items()->get();

        if ($items->isEmpty()) {
            return;
        }

        $weights = $items->mapWithKeys(fn ($i) => [$i->id => (int) $i->getRawOriginal('line_subtotal_minor')])->all();

        if (array_sum($weights) <= 0) {
            return;
        }

        $discount = Money::ofMinor((int) $order->getRawOriginal('discount_minor'), $order->currency);
        $tax = Money::ofMinor((int) $order->getRawOriginal('tax_minor'), $order->currency);

        $discountShares = $discount->isZero() ? [] : $discount->allocateByWeights($weights);
        $taxShares = $tax->isZero() ? [] : $tax->allocateByWeights($weights);

        foreach ($items as $item) {
            $item->forceFill([
                'line_discount_minor' => $discountShares[$item->id]->minor ?? 0,
                'line_tax_minor' => $taxShares[$item->id]->minor ?? 0,
            ])->save();
        }
    }

    private function recordDiscountRedemption(Order $order, Cart $cart, CartTotals $totals): void
    {
        if ($cart->discount === null || $totals->discount->isZero()) {
            return;
        }

        DiscountRedemption::create([
            'discount_id' => $cart->discount->id,
            'order_id' => $order->id,
            'email' => $order->email,
            'amount_minor' => $totals->discount->minor,
        ]);

        $cart->discount->increment('used_count');
    }

    private function generateNumber(): string
    {
        do {
            $number = 'PS-'.now()->format('ymd').'-'.strtoupper(Str::random(5));
        } while (Order::query()->where('number', $number)->exists());

        return $number;
    }
}
