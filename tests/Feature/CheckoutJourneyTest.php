<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Checkout\CartService;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Checkout\QuoteExpiredException;
use App\Domain\Shipping\ShippingQuoteService;
use App\Domain\Shipping\WarehouseSelector;
use App\Models\Cart;
use App\Models\Market;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The end-to-end guest purchase, through the real services.
 */
class CheckoutJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Market $market;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->market = Market::default();
        settings()->set('inventory.stock_buffer', 0);
    }

    #[Test]
    public function a_guest_can_complete_a_purchase(): void
    {
        $variant = $this->stockedVariant();
        $cart = $this->cartWith($variant, 2);

        $quotes = $this->quoteFor($cart);
        $this->assertFalse($quotes->isBlocked());
        $this->assertGreaterThan(0, $quotes->quotes->count());

        $order = app(CheckoutService::class)->place(
            $cart,
            ['email' => 'buyer@example.test', 'phone' => '5551234567'],
            $this->address(),
            [$quotes->cheapestPerParcel()->first()->id],
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertTrue($order->is_guest);
        $this->assertSame(Order::PAYMENT_PENDING, $order->payment_state);
        $this->assertSame(2, $order->items->first()->quantity);

        // The cart is emptied so a refresh cannot place a second order.
        $this->assertTrue($cart->fresh()->isEmpty());
    }

    #[Test]
    public function the_order_totals_add_up_exactly(): void
    {
        $variant = $this->stockedVariant();
        $cart = $this->cartWith($variant, 3);
        $quotes = $this->quoteFor($cart);

        $order = app(CheckoutService::class)->place(
            $cart,
            ['email' => 'buyer@example.test', 'phone' => '5551234567'],
            $this->address(),
            [$quotes->cheapestPerParcel()->first()->id],
        );

        $subtotal = (int) $order->getRawOriginal('subtotal_minor');
        $discount = (int) $order->getRawOriginal('discount_minor');
        $shipping = (int) $order->getRawOriginal('shipping_minor');
        $tax = (int) $order->getRawOriginal('tax_minor');
        $total = (int) $order->getRawOriginal('total_minor');

        $this->assertSame($subtotal - $discount + $shipping + $tax, $total);

        // Line subtotals must reconstruct the order subtotal exactly.
        $lineSum = (int) $order->items->sum(fn ($i) => (int) $i->getRawOriginal('line_subtotal_minor'));
        $this->assertSame($subtotal, $lineSum);
    }

    #[Test]
    public function an_expired_quote_is_refused_rather_than_honoured_or_silently_repriced(): void
    {
        $variant = $this->stockedVariant();
        $cart = $this->cartWith($variant, 1);
        $quotes = $this->quoteFor($cart);

        $quote = $quotes->cheapestPerParcel()->first();
        $quote->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->expectException(QuoteExpiredException::class);

        app(CheckoutService::class)->place(
            $cart,
            ['email' => 'buyer@example.test', 'phone' => '5551234567'],
            $this->address(),
            [$quote->id],
        );
    }

    #[Test]
    public function the_order_snapshots_the_delivery_promise_it_was_sold_under(): void
    {
        $variant = $this->stockedVariant();
        $cart = $this->cartWith($variant, 1);
        $quotes = $this->quoteFor($cart);
        $quote = $quotes->cheapestPerParcel()->first();

        $order = app(CheckoutService::class)->place(
            $cart,
            ['email' => 'buyer@example.test', 'phone' => '5551234567'],
            $this->address(),
            [$quote->id],
        );

        $promise = $order->totals_breakdown['delivery_promise'] ?? [];

        $this->assertNotEmpty($promise);
        $this->assertSame($quote->service_name, $promise[0]['service']);
        // Provenance travels with the promise.
        $this->assertArrayHasKey('source', $promise[0]);
    }

    #[Test]
    public function a_discount_is_allocated_across_lines_without_losing_a_cent(): void
    {
        $variant = $this->stockedVariant();
        $cart = $this->cartWith($variant, 3);

        \App\Models\Discount::create([
            'code' => 'TENOFF', 'name' => 'Ten percent',
            'type' => \App\Models\Discount::TYPE_PERCENTAGE,
            'percentage' => 10, 'currency' => 'USD', 'is_active' => true,
        ]);

        app(CartService::class)->applyDiscount($cart, 'TENOFF');
        $cart = $cart->fresh()->load('items.variant.product');

        $quotes = $this->quoteFor($cart);

        $order = app(CheckoutService::class)->place(
            $cart,
            ['email' => 'buyer@example.test', 'phone' => '5551234567'],
            $this->address(),
            [$quotes->cheapestPerParcel()->first()->id],
        );

        $orderDiscount = (int) $order->getRawOriginal('discount_minor');
        $lineDiscounts = (int) $order->items->sum(fn ($i) => (int) $i->getRawOriginal('line_discount_minor'));

        $this->assertGreaterThan(0, $orderDiscount);
        $this->assertSame($orderDiscount, $lineDiscounts);
    }

    #[Test]
    public function an_empty_cart_cannot_be_checked_out(): void
    {
        $cart = Cart::create([
            'token' => (string) \Illuminate\Support\Str::uuid(),
            'market_id' => $this->market->id,
            'currency' => 'USD',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('basket is empty');

        app(CheckoutService::class)->place(
            $cart,
            ['email' => 'buyer@example.test', 'phone' => '5551234567'],
            $this->address(),
            [1],
        );
    }

    #[Test]
    public function a_cart_cannot_exceed_available_stock(): void
    {
        $variant = $this->stockedVariant(quantity: 3);
        $cart = $this->cartWith($variant, 99);

        // Capped at what actually exists, rather than accepted and failed later.
        $this->assertSame(3, $cart->fresh()->items->first()->quantity);
    }

    #[Test]
    public function an_item_with_unknown_availability_cannot_be_added(): void
    {
        $variant = $this->stockedVariant(quantity: null, known: false);

        $cart = Cart::create([
            'token' => (string) \Illuminate\Support\Str::uuid(),
            'market_id' => $this->market->id,
            'currency' => 'USD',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot confirm availability');

        app(CartService::class)->add($cart, $variant, 1);
    }

    /* ---------------------------------------------------------------- */

    private function quoteFor(Cart $cart)
    {
        $plan = app(WarehouseSelector::class)->plan(
            $cart->items->map(fn ($i) => ['variant' => $i->variant, 'quantity' => $i->quantity]),
            $this->market,
        );

        return app(ShippingQuoteService::class)->quote(
            $plan,
            $this->market,
            $this->address(),
            $cart->subtotal(),
        );
    }

    private function address(): array
    {
        return [
            'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'line1' => '1 Test Street', 'city' => 'Newark', 'state' => 'NJ',
            'postal_code' => '07101', 'country_code' => 'US', 'phone' => '5551234567',
        ];
    }

    private function cartWith(ProductVariant $variant, int $quantity): Cart
    {
        $cart = Cart::create([
            'token' => (string) \Illuminate\Support\Str::uuid(),
            'market_id' => $this->market->id,
            'currency' => 'USD',
        ]);

        app(CartService::class)->add($cart, $variant, $quantity);

        return $cart->fresh()->load('items.variant.product');
    }

    private function stockedVariant(?int $quantity = 50, bool $known = true): ProductVariant
    {
        $product = Product::create([
            'slug' => 'p-'.uniqid(),
            'name' => 'Test product',
            'status' => Product::STATUS_PUBLISHED,
            'supplier_id' => Supplier::cj()->id,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.uniqid(),
            'currency' => 'USD',
            'computed_price_minor' => 1999,
            'supplier_cost_minor' => 700,
            'supplier_cost_currency' => 'USD',
            'supplier_variant_id' => 'DEMO-V-1001-S',
            'weight_grams' => 200,
            'is_active' => true,
        ]);

        WarehouseStock::create([
            'warehouse_id' => Warehouse::where('code', 'US-NJ')->first()->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'quantity_known' => $known,
            'synced_at' => now(),
            'stale_after' => now()->addDay(),
        ]);

        return $variant->fresh();
    }
}
