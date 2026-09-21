<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Settings\Branding;
use App\Domain\Settings\SettingsRepository;
use App\Models\Market;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A brand change must reach everything new, and rewrite nothing historical.
 */
class BrandingPropagationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    #[Test]
    public function a_name_change_reaches_the_storefront_immediately(): void
    {
        $this->get('/')->assertSuccessful()->assertSee('Pet Store', escape: false);

        settings()->set('brand.name', 'Wagtail & Whisker');
        app(Branding::class)->flush();

        $this->get('/')->assertSuccessful()->assertSee('Wagtail &amp; Whisker', escape: false);
    }

    #[Test]
    public function an_accent_colour_change_reaches_the_rendered_page(): void
    {
        settings()->set('brand.accent_color', '#B3541E');
        app(Branding::class)->flush();

        $this->get('/')->assertSuccessful()->assertSee('--brand-accent: #B3541E', escape: false);
    }

    #[Test]
    public function seo_metadata_follows_the_brand_settings(): void
    {
        settings()->setMany([
            'seo.default_title' => 'A Very Specific Title',
            'seo.default_description' => 'A very specific description.',
        ], 'seo');
        app(Branding::class)->flush();

        $this->get('/')
            ->assertSee('A Very Specific Title', escape: false)
            ->assertSee('A very specific description.', escape: false);
    }

    #[Test]
    public function an_order_keeps_the_branding_it_was_placed_under(): void
    {
        settings()->set('brand.name', 'Original Name');
        app(Branding::class)->flush();

        $order = $this->orderWithSnapshot();
        $this->assertSame('Original Name', $order->brand_snapshot['name']);

        // The shop rebrands afterwards.
        settings()->set('brand.name', 'Completely New Name');
        app(Branding::class)->flush();

        // The historical record is untouched.
        $this->assertSame('Original Name', $order->fresh()->brand_snapshot['name']);
        $this->assertSame('Completely New Name', branding('name'));
    }

    #[Test]
    public function the_business_snapshot_is_frozen_on_the_order(): void
    {
        settings()->set('business.legal_name', 'Old Trading Ltd');
        app(Branding::class)->flush();

        $order = $this->orderWithSnapshot();

        settings()->set('business.legal_name', 'New Trading LLC');
        app(Branding::class)->flush();

        $this->assertSame('Old Trading Ltd', $order->fresh()->business_snapshot['legal_name']);
    }

    #[Test]
    public function writing_a_setting_invalidates_the_derived_caches(): void
    {
        $branding = app(Branding::class);

        $this->assertSame('Pet Store', $branding->name());

        // A write through the repository must drop the branding cache too,
        // otherwise the storefront would serve the old name until the cache
        // happened to expire.
        app(SettingsRepository::class)->set('brand.name', 'Cache Test');

        $this->assertSame('Cache Test', app(Branding::class)->name());
    }

    private function orderWithSnapshot(): Order
    {
        $branding = app(Branding::class);

        return Order::create([
            'number' => 'PS-TEST-'.strtoupper(substr(uniqid(), -6)),
            'market_id' => Market::default()->id,
            'currency' => 'USD',
            'is_guest' => true,
            'email' => 'buyer@example.test',
            'payment_state' => Order::PAYMENT_PAID,
            'approval_state' => Order::APPROVAL_AWAITING,
            'supplier_order_state' => Order::SUPPLIER_NOT_SUBMITTED,
            'supplier_payment_state' => Order::SUPPLIER_PAY_NOT_PAID,
            'shipment_state' => Order::SHIPMENT_NONE,
            'subtotal_minor' => 2500,
            'total_minor' => 2500,
            'shipping_address' => ['country_code' => 'US', 'postal_code' => '10001'],
            'brand_snapshot' => $branding->snapshot(),
            'business_snapshot' => $branding->businessSnapshot(),
            'placed_at' => now(),
        ]);
    }
}
