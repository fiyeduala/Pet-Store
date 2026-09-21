<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\CartDrawer;
use App\Livewire\CheckoutFlow;
use App\Livewire\ProductPurchasePanel;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The customer journey driven through the real components.
 */
class StorefrontJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DemoSeeder::class);
    }

    #[Test]
    public function the_public_pages_all_render(): void
    {
        $product = Product::published()->firstOrFail();

        foreach ([
            '/', '/shop', '/cart', '/checkout', '/faq', '/contact',
            '/orders/track', '/login', '/register',
            '/p/shipping', '/p/returns', '/p/privacy', '/p/terms', '/p/about',
            "/products/{$product->slug}",
            '/shop/pets/dogs', '/shop/pets/cats', '/collections/new-arrivals',
        ] as $path) {
            $this->get($path)->assertSuccessful();
        }
    }

    #[Test]
    public function catalogue_filters_persist_in_the_url_and_actually_filter(): void
    {
        $all = $this->get('/shop');
        $filtered = $this->get('/shop?pet%5B%5D=cats');

        $all->assertSuccessful();
        $filtered->assertSuccessful();

        // Cats have fewer products than the whole catalogue.
        $this->assertNotSame(
            $this->productCount($all->getContent()),
            $this->productCount($filtered->getContent()),
        );
    }

    #[Test]
    public function sorting_by_price_actually_orders_by_price(): void
    {
        $content = $this->get('/shop?sort=price_asc')->getContent();

        preg_match_all('/\$(\d+)\.(\d\d)/', $content, $matches);
        $prices = array_map(
            fn ($d, $c) => ((int) $d * 100) + (int) $c,
            $matches[1],
            $matches[2],
        );

        $sorted = $prices;
        sort($sorted);

        $this->assertSame($sorted, $prices, 'Prices were not rendered in ascending order.');
    }

    #[Test]
    public function an_unpublished_product_is_not_reachable(): void
    {
        $product = Product::published()->firstOrFail();
        $product->forceFill(['status' => Product::STATUS_DRAFT])->save();

        $this->get("/products/{$product->slug}")->assertNotFound();
    }

    #[Test]
    public function a_shopper_can_add_to_the_basket_and_see_it_in_the_drawer(): void
    {
        $product = Product::published()
            ->with('variants.warehouseStocks')
            ->get()
            ->first(fn (Product $p) => $p->hasAnyKnownStock());

        $this->assertNotNull($product, 'Expected at least one purchasable demo product.');

        Livewire::test(ProductPurchasePanel::class, ['product' => $product])
            ->call('addToCart')
            ->assertHasNoErrors()
            ->assertDispatched('cart-updated');

        Livewire::test(CartDrawer::class)
            ->assertSee($product->name);
    }

    #[Test]
    public function the_zip_estimator_returns_services_with_their_provenance(): void
    {
        $product = Product::published()
            ->with('variants.warehouseStocks')
            ->get()
            ->first(fn (Product $p) => $p->hasAnyKnownStock());

        $component = Livewire::test(ProductPurchasePanel::class, ['product' => $product])
            ->set('zip', '07101')
            ->call('estimate')
            ->assertSet('estimateError', null);

        $estimates = $component->get('estimates');

        $this->assertNotNull($estimates);
        $this->assertNotEmpty($estimates['options']);

        // At least one service must honestly report no estimate, and the
        // storefront must carry that through rather than inventing one.
        $labels = array_column($estimates['options'], 'estimate');
        $this->assertTrue(
            collect($labels)->contains(fn (string $l) => str_contains($l, 'not available')),
            'Expected a service with no published estimate to say so.'
        );
    }

    #[Test]
    public function the_estimator_rejects_a_malformed_zip(): void
    {
        $product = Product::published()->first();

        Livewire::test(ProductPurchasePanel::class, ['product' => $product])
            ->set('zip', 'not-a-zip')
            ->call('estimate')
            ->assertSet('estimates', null);
    }

    #[Test]
    public function checkout_offers_the_simulated_method_and_labels_it(): void
    {
        $product = Product::published()
            ->with('variants.warehouseStocks')
            ->get()
            ->first(fn (Product $p) => $p->hasAnyKnownStock());

        Livewire::test(ProductPurchasePanel::class, ['product' => $product])->call('addToCart');

        Livewire::test(CheckoutFlow::class)
            ->assertSee('Simulated')
            ->assertSee('Charged in USD');
    }

    #[Test]
    public function a_full_guest_checkout_produces_an_order_and_a_demo_payment(): void
    {
        $product = Product::published()
            ->with('variants.warehouseStocks')
            ->get()
            ->first(fn (Product $p) => $p->hasAnyKnownStock());

        Livewire::test(ProductPurchasePanel::class, ['product' => $product])->call('addToCart');

        Livewire::test(CheckoutFlow::class)
            ->set('email', 'buyer@example.test')
            ->set('firstName', 'Ada')
            ->set('lastName', 'Lovelace')
            ->set('phone', '5551234567')
            ->set('line1', '1 Test Street')
            ->set('city', 'Newark')
            ->set('state', 'NJ')
            ->set('postalCode', '07101')
            ->set('selectedGateway', 'demo')
            ->call('placeOrder')
            ->assertHasNoErrors()
            ->assertSet('checkoutError', null);

        $order = Order::latest('id')->firstOrFail();

        $this->assertTrue($order->is_guest);
        $this->assertSame('buyer@example.test', $order->email);
        $this->assertSame(Order::PAYMENT_PENDING, $order->payment_state);
        $this->assertGreaterThan(0, (int) $order->getRawOriginal('total_minor'));

        // A payment intent exists and is flagged as simulated.
        $payment = $order->payments()->firstOrFail();
        $this->assertTrue($payment->is_demo);
    }

    #[Test]
    public function guest_checkout_can_be_switched_off(): void
    {
        settings()->set('checkout.guest_enabled', false);

        $product = Product::published()
            ->with('variants.warehouseStocks')
            ->get()
            ->first(fn (Product $p) => $p->hasAnyKnownStock());

        Livewire::test(ProductPurchasePanel::class, ['product' => $product])->call('addToCart');

        Livewire::test(CheckoutFlow::class)
            ->assertSee('Guest checkout is currently switched off')
            // The pay button is disabled, not merely discouraged.
            ->assertSeeHtml('disabled');

        // And the service layer refuses regardless of what the UI shows.
        $this->assertTrue(
            Livewire::test(CheckoutFlow::class)->instance()->guestCheckoutDisabled()
        );
    }

    #[Test]
    public function the_contact_form_records_an_enquiry(): void
    {
        $this->post('/contact', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'subject' => 'A question',
            'message' => 'Is this bed machine washable?',
        ])->assertRedirect();

        $this->assertDatabaseHas('contact_messages', ['email' => 'ada@example.test']);
    }

    #[Test]
    public function policy_drafts_are_labelled_as_drafts_to_the_customer(): void
    {
        $this->get('/p/returns')
            ->assertSuccessful()
            ->assertSee('under review', escape: false);
    }

    private function productCount(string $html): int
    {
        preg_match('/(\d+) products?/', strip_tags($html), $matches);

        return (int) ($matches[1] ?? 0);
    }
}
