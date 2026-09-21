<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Shipping\DeliveryEstimatePresenter;
use App\Domain\Shipping\ShippingQuoteService;
use App\Domain\Shipping\WarehouseSelector;
use App\Models\Market;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingQuote;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShippingAndStockTest extends TestCase
{
    use RefreshDatabase;

    private Market $market;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->market = Market::default();
    }

    /* ---- Stock semantics ---- */

    #[Test]
    public function unknown_stock_is_not_treated_as_available(): void
    {
        $variant = $this->variant();

        $this->stock($variant, 'US-NJ', quantity: null, known: false);

        $this->assertFalse($variant->fresh()->hasKnownStock());
        $this->assertSame(0, $variant->fresh()->availableStock());
        $this->assertFalse($variant->fresh()->isPurchasable());
    }

    #[Test]
    public function unknown_stock_is_distinguishable_from_zero_stock(): void
    {
        $unknown = $this->variant();
        $this->stock($unknown, 'US-NJ', quantity: null, known: false);

        $zero = $this->variant();
        $this->stock($zero, 'US-NJ', quantity: 0, known: true);

        // Both are unavailable, but only one is genuinely "out of stock".
        $this->assertFalse($unknown->fresh()->hasKnownStock());
        $this->assertTrue($zero->fresh()->hasKnownStock());
    }

    #[Test]
    public function a_stale_reading_reverts_to_unknown_rather_than_being_trusted(): void
    {
        $variant = $this->variant();

        WarehouseStock::create([
            'warehouse_id' => Warehouse::where('code', 'US-NJ')->first()->id,
            'product_variant_id' => $variant->id,
            'quantity' => 50,
            'quantity_known' => true,
            'synced_at' => now()->subDays(5),
            'stale_after' => now()->subDay(),
        ]);

        $this->assertFalse($variant->fresh()->hasKnownStock());
        $this->assertSame(0, $variant->fresh()->availableStock());
    }

    #[Test]
    public function the_safety_buffer_reduces_sellable_stock(): void
    {
        settings()->set('inventory.stock_buffer', 3);

        $variant = $this->variant();
        $this->stock($variant, 'US-NJ', quantity: 10, known: true);

        $this->assertSame(7, $variant->fresh()->availableStock());
    }

    #[Test]
    public function warehouse_stock_is_never_merged_across_countries_for_a_domestic_order(): void
    {
        // Isolate the country filter from the separately tested buffer.
        settings()->set('inventory.stock_buffer', 0);

        $variant = $this->variant();
        $this->stock($variant, 'US-NJ', quantity: 2, known: true);
        $this->stock($variant, 'CN-GZ', quantity: 500, known: true);

        $usWarehouses = Warehouse::where('country_code', 'US')->pluck('id')->all();

        // Only the US stock counts for a US-only order, despite plenty in China.
        $this->assertSame(2, $variant->fresh()->availableStock($usWarehouses));
    }

    /* ---- Warehouse selection ---- */

    #[Test]
    public function running_out_of_domestic_stock_does_not_silently_use_an_overseas_warehouse(): void
    {
        $variant = $this->variant();
        $this->stock($variant, 'US-NJ', quantity: 0, known: true);
        $this->stock($variant, 'CN-GZ', quantity: 500, known: true);

        Warehouse::where('code', 'CN-GZ')->update(['is_enabled' => true]);

        $plan = app(WarehouseSelector::class)->plan(
            collect([['variant' => $variant->fresh(), 'quantity' => 1]]),
            $this->market,
            allowOverseas: false,
        );

        $this->assertFalse($plan->isComplete());
        $this->assertNotEmpty($plan->unfulfillable);
    }

    #[Test]
    public function overseas_fulfilment_works_only_when_deliberately_enabled(): void
    {
        $variant = $this->variant();
        $this->stock($variant, 'US-NJ', quantity: 0, known: true);
        $this->stock($variant, 'CN-GZ', quantity: 500, known: true);

        Warehouse::where('code', 'CN-GZ')->update(['is_enabled' => true]);

        $plan = app(WarehouseSelector::class)->plan(
            collect([['variant' => $variant->fresh(), 'quantity' => 1]]),
            $this->market,
            allowOverseas: true,
        );

        $this->assertTrue($plan->isComplete());
        $this->assertSame('CN-GZ', $plan->parcels[0]->warehouse->code);
    }

    #[Test]
    public function an_order_splits_across_warehouses_when_no_single_one_can_cover_it(): void
    {
        $a = $this->variant();
        $this->stock($a, 'US-NJ', quantity: 10, known: true);
        $this->stock($a, 'US-CA', quantity: 0, known: true);

        $b = $this->variant();
        $this->stock($b, 'US-NJ', quantity: 0, known: true);
        $this->stock($b, 'US-CA', quantity: 10, known: true);

        $plan = app(WarehouseSelector::class)->plan(
            collect([
                ['variant' => $a->fresh(), 'quantity' => 1],
                ['variant' => $b->fresh(), 'quantity' => 1],
            ]),
            $this->market,
        );

        $this->assertTrue($plan->isSplit());
        $this->assertSame(2, $plan->parcelCount());
        $this->assertTrue($plan->isComplete());
    }

    #[Test]
    public function warehouse_selection_is_deterministic(): void
    {
        $variant = $this->variant();
        $this->stock($variant, 'US-NJ', quantity: 10, known: true);
        $this->stock($variant, 'US-CA', quantity: 10, known: true);

        $codes = [];

        for ($i = 0; $i < 5; $i++) {
            $plan = app(WarehouseSelector::class)->plan(
                collect([['variant' => $variant->fresh(), 'quantity' => 1]]),
                $this->market,
            );
            $codes[] = $plan->parcels[0]->warehouse->code;
        }

        $this->assertCount(1, array_unique($codes));
    }

    /* ---- Delivery estimate wording ---- */

    #[Test]
    public function a_missing_estimate_is_reported_as_unavailable_not_invented(): void
    {
        $quote = $this->quote(['estimate_min' => null, 'estimate_unit' => ShippingQuote::UNIT_UNKNOWN]);

        $label = app(DeliveryEstimatePresenter::class)->forQuote($quote);

        $this->assertStringContainsString('not available', $label);
        $this->assertDoesNotMatchRegularExpression('/\d+\s*(–|-)?\s*\d*\s*(business )?days/', $label);
    }

    #[Test]
    public function business_days_and_calendar_days_are_never_conflated(): void
    {
        $business = app(DeliveryEstimatePresenter::class)->forQuote($this->quote([
            'estimate_min' => 3, 'estimate_max' => 7,
            'estimate_unit' => ShippingQuote::UNIT_BUSINESS_DAYS,
            'estimate_type' => ShippingQuote::TYPE_TRANSIT,
        ]));

        $calendar = app(DeliveryEstimatePresenter::class)->forQuote($this->quote([
            'estimate_min' => 3, 'estimate_max' => 7,
            'estimate_unit' => ShippingQuote::UNIT_DAYS,
            'estimate_type' => ShippingQuote::TYPE_TRANSIT,
        ]));

        $this->assertStringContainsString('business days', $business);
        $this->assertStringNotContainsString('business days', $calendar);
        $this->assertNotSame($business, $calendar);
    }

    #[Test]
    public function a_number_without_a_unit_says_the_unit_is_unknown(): void
    {
        $label = app(DeliveryEstimatePresenter::class)->forQuote($this->quote([
            'estimate_min' => 5, 'estimate_max' => 9,
            // The carrier gave a number but not whether they are working days.
            'estimate_unit' => ShippingQuote::UNIT_UNKNOWN,
            'estimate_type' => ShippingQuote::TYPE_TRANSIT,
        ]));

        $this->assertStringContainsString('did not specify', $label);
    }

    #[Test]
    public function handling_time_is_stated_separately_from_transit_time(): void
    {
        $label = app(DeliveryEstimatePresenter::class)->forQuote($this->quote([
            'estimate_min' => 3, 'estimate_max' => 5,
            'estimate_unit' => ShippingQuote::UNIT_BUSINESS_DAYS,
            'estimate_type' => ShippingQuote::TYPE_TRANSIT,
            'handling_min_days' => 1, 'handling_max_days' => 2,
        ]));

        $this->assertStringContainsString('in transit', $label);
        $this->assertStringContainsString('handling before dispatch', $label);
    }

    #[Test]
    public function an_owner_policy_estimate_is_labelled_as_the_stores_own(): void
    {
        $label = app(DeliveryEstimatePresenter::class)->forQuote($this->quote([
            'estimate_min' => 4, 'estimate_max' => 8,
            'estimate_unit' => ShippingQuote::UNIT_BUSINESS_DAYS,
            'estimate_type' => ShippingQuote::TYPE_TOTAL,
            'estimate_source' => ShippingQuote::SOURCE_OWNER_POLICY,
        ]));

        $this->assertStringContainsString('store estimate', $label);
    }

    #[Test]
    public function an_excluded_destination_blocks_checkout_with_a_reason(): void
    {
        $variant = $this->variant();
        $this->stock($variant, 'US-NJ', quantity: 10, known: true);

        $plan = app(WarehouseSelector::class)->plan(
            collect([['variant' => $variant->fresh(), 'quantity' => 1]]),
            $this->market,
        );

        // AE is a military APO zone, seeded as excluded.
        $set = app(ShippingQuoteService::class)->quote(
            $plan,
            $this->market,
            ['country_code' => 'US', 'state' => 'AE', 'postal_code' => '09001', 'city' => 'APO'],
            Money::ofMinor(2500, 'USD'),
        );

        $this->assertTrue($set->isBlocked());
        $this->assertStringContainsString('APO', (string) $set->blockedReason);
    }

    /* ---------------------------------------------------------------- */

    private function quote(array $overrides): ShippingQuote
    {
        return new ShippingQuote($overrides + [
            'quote_group' => 'g', 'destination_hash' => 'h',
            'market_id' => $this->market->id,
            'service_name' => 'Test service',
            'amount_minor' => 599, 'currency' => 'USD',
            'estimate_source' => ShippingQuote::SOURCE_SUPPLIER,
            'quoted_at' => now(), 'expires_at' => now()->addHour(),
        ]);
    }

    private function variant(): ProductVariant
    {
        $product = Product::create([
            'slug' => 'p-'.uniqid(),
            'name' => 'Test product',
            'status' => Product::STATUS_PUBLISHED,
            'supplier_id' => Supplier::cj()->id,
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.uniqid(),
            'currency' => 'USD',
            'computed_price_minor' => 2500,
            'supplier_cost_minor' => 1000,
            'supplier_cost_currency' => 'USD',
            'supplier_variant_id' => 'DEMO-V-1001-S',
            'weight_grams' => 200,
            'is_active' => true,
        ]);
    }

    private function stock(ProductVariant $variant, string $warehouseCode, ?int $quantity, bool $known): void
    {
        WarehouseStock::updateOrCreate(
            [
                'warehouse_id' => Warehouse::where('code', $warehouseCode)->firstOrFail()->id,
                'product_variant_id' => $variant->id,
            ],
            [
                'quantity' => $quantity,
                'quantity_known' => $known,
                'synced_at' => now(),
                'stale_after' => now()->addDay(),
            ]
        );
    }
}
