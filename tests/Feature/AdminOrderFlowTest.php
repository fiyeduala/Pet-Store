<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Checkout\CheckoutService;
use App\Domain\Payments\PaymentService;
use App\Domain\Shipping\ShippingQuoteService;
use App\Domain\Shipping\WarehouseSelector;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\Cart;
use App\Models\Fulfilment;
use App\Models\Market;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin side of a real order, driven through the actual Filament page.
 */
class AdminOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DemoSeeder::class);
        $this->order = $this->placeAndPayOrder();
    }

    #[Test]
    public function the_order_page_renders_with_all_five_states(): void
    {
        Livewire::actingAs($this->owner())
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->assertSuccessful()
            ->assertSee('Customer payment')
            ->assertSee('Admin approval')
            ->assertSee('Supplier order')
            ->assertSee('Supplier payment')
            ->assertSee('Shipment');
    }

    #[Test]
    public function the_order_page_labels_contribution_not_profit(): void
    {
        Livewire::actingAs($this->owner())
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->assertSee('Contribution')
            ->assertSee('Before advertising and overhead')
            ->assertDontSee('Net profit');
    }

    #[Test]
    public function a_paid_order_waits_for_approval_by_default(): void
    {
        $this->assertSame(Order::PAYMENT_PAID, $this->order->payment_state);
        $this->assertSame(Order::APPROVAL_AWAITING, $this->order->approval_state);
        $this->assertSame(Order::SUPPLIER_NOT_SUBMITTED, $this->order->supplier_order_state);
        $this->assertSame(Order::SUPPLIER_PAY_NOT_PAID, $this->order->supplier_payment_state);
    }

    #[Test]
    public function approving_does_not_submit_or_pay_anything(): void
    {
        Livewire::actingAs($this->owner())
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->callAction('approve', ['also_submit' => false, 'authorise_charge' => false]);

        $order = $this->order->fresh();

        $this->assertSame(Order::APPROVAL_APPROVED, $order->approval_state);
        // The two things approval must NOT have done.
        $this->assertSame(Order::SUPPLIER_NOT_SUBMITTED, $order->supplier_order_state);
        $this->assertSame(Order::SUPPLIER_PAY_NOT_PAID, $order->supplier_payment_state);
        $this->assertFalse($order->approval_authorised_supplier_charge);
    }

    #[Test]
    public function approving_with_submit_creates_the_supplier_order_but_still_does_not_pay(): void
    {
        Livewire::actingAs($this->owner())
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->callAction('approve', ['also_submit' => true, 'authorise_charge' => false]);

        $order = $this->order->fresh();

        $this->assertSame(Order::SUPPLIER_CONFIRMED, $order->supplier_order_state);
        $this->assertSame(Order::SUPPLIER_PAY_NOT_PAID, $order->supplier_payment_state);

        $fulfilment = $order->fulfilments()->firstOrFail();
        $this->assertSame(Fulfilment::STATE_CONFIRMED, $fulfilment->state);
        $this->assertNotNull($fulfilment->supplier_order_id);
        $this->assertNull($fulfilment->supplierPayment);
    }

    #[Test]
    public function paying_the_supplier_is_a_separate_owner_only_action(): void
    {
        $page = Livewire::actingAs($this->owner())
            ->test(ViewOrder::class, ['record' => $this->order->number]);

        $page->callAction('approve', ['also_submit' => true, 'authorise_charge' => false]);

        Livewire::actingAs($this->owner())
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->callAction('pay_supplier', ['confirm' => true]);

        $order = $this->order->fresh();

        $this->assertSame(Order::SUPPLIER_PAY_PAID, $order->supplier_payment_state);
        $this->assertTrue($order->fulfilments()->firstOrFail()->supplierPayment->isSettled());
    }

    #[Test]
    public function an_operations_user_cannot_authorise_a_supplier_payment(): void
    {
        Livewire::actingAs($this->owner())
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->callAction('approve', ['also_submit' => true, 'authorise_charge' => false]);

        $ops = User::factory()->create(['is_staff' => true]);
        $ops->syncRoles(['operations']);

        Livewire::actingAs($ops)
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->assertActionHidden('pay_supplier');
    }

    #[Test]
    public function a_support_user_cannot_approve_an_order(): void
    {
        $support = User::factory()->create(['is_staff' => true]);
        $support->syncRoles(['support']);

        Livewire::actingAs($support)
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->assertActionHidden('approve');
    }

    #[Test]
    public function holding_an_order_records_the_reason(): void
    {
        Livewire::actingAs($this->owner())
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->callAction('reject', ['reason' => 'Customer asked us to wait.']);

        $order = $this->order->fresh();

        $this->assertSame(Order::APPROVAL_ON_HOLD, $order->approval_state);
        $this->assertSame('Customer asked us to wait.', $order->hold_reason);
    }

    #[Test]
    public function a_refund_recorded_without_processing_does_not_claim_money_moved(): void
    {
        Livewire::actingAs($this->owner())
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->callAction('refund', [
                'amount' => '5.00',
                'reason' => 'Goodwill',
                'process_now' => false,
            ]);

        $refund = $this->order->fresh()->refunds()->firstOrFail();

        $this->assertSame(\App\Models\Refund::STATUS_REQUESTED, $refund->status);
        $this->assertFalse($refund->isSettled());
        // The order's refunded total is untouched until it settles.
        $this->assertSame(0, (int) $this->order->fresh()->getRawOriginal('refunded_minor'));
    }

    #[Test]
    public function cancelling_before_submission_is_guaranteed(): void
    {
        Livewire::actingAs($this->owner())
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->callAction('cancel', ['reason' => 'Customer changed their mind.']);

        $this->assertNotNull($this->order->fresh()->cancelled_at);
    }

    #[Test]
    public function cancelling_after_submission_is_not_guaranteed_and_says_so(): void
    {
        Livewire::actingAs($this->owner())
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->callAction('approve', ['also_submit' => true, 'authorise_charge' => false]);

        Livewire::actingAs($this->owner())
            ->test(ViewOrder::class, ['record' => $this->order->number])
            ->callAction('cancel', ['reason' => 'Customer changed their mind.']);

        // Not cancelled outright; raised for a human to resolve with CJ.
        $this->assertNull($this->order->fresh()->cancelled_at);
        $this->assertDatabaseHas('operational_exceptions', [
            'order_id' => $this->order->id,
            'type' => 'refund_dispatch_race',
        ]);
    }

    /* ---------------------------------------------------------------- */

    private function owner(): User
    {
        $user = User::factory()->create(['is_staff' => true]);
        $user->syncRoles(['owner']);

        return $user;
    }

    private function placeAndPayOrder(): Order
    {
        $market = Market::default();

        $product = Product::published()
            ->with('variants.warehouseStocks')
            ->get()
            ->first(fn (Product $p) => $p->hasAnyKnownStock());

        $variant = $product->activeVariantsLoaded()->first(fn ($v) => $v->isPurchasable());

        $cart = Cart::create([
            'token' => (string) Str::uuid(),
            'market_id' => $market->id,
            'currency' => 'USD',
        ]);

        app(\App\Domain\Checkout\CartService::class)->add($cart, $variant, 1);
        $cart = $cart->fresh()->load('items.variant.product');

        $address = [
            'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'line1' => '1 Test Street', 'city' => 'Newark', 'state' => 'NJ',
            'postal_code' => '07101', 'country_code' => 'US', 'phone' => '5551234567',
        ];

        $plan = app(WarehouseSelector::class)->plan(
            $cart->items->map(fn ($i) => ['variant' => $i->variant, 'quantity' => $i->quantity]),
            $market,
        );

        $quotes = app(ShippingQuoteService::class)->quote($plan, $market, $address, $cart->subtotal());

        $order = app(CheckoutService::class)->place(
            $cart,
            ['email' => 'buyer@example.test', 'phone' => '5551234567'],
            $address,
            [$quotes->cheapestPerParcel()->first()->id],
        );

        $gateway = PaymentGateway::where('code', 'demo')->firstOrFail();
        $result = app(PaymentService::class)->begin($order, $gateway);
        app(PaymentService::class)->captureAndApply($result['payment']);

        return $order->fresh();
    }
}
