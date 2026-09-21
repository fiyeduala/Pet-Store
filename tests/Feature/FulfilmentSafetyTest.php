<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fulfilment\FulfilmentService;
use App\Domain\Fulfilment\SupplierPaymentService;
use App\Domain\Supplier\Adapters\Demo\DemoSupplierAdapter;
use App\Domain\Supplier\Services\SupplierRegistry;
use App\Models\Fulfilment;
use App\Models\Market;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cases where getting it wrong costs real money:
 * timeouts, duplicate purchases, duplicate supplier payments, and
 * demo money reaching a live supplier.
 */
class FulfilmentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->supplier = Supplier::where('code', 'cjdropshipping')->firstOrFail();
        $this->warehouse = Warehouse::where('code', 'US-NJ')->firstOrFail();
    }

    #[Test]
    public function an_unpaid_order_is_never_submitted(): void
    {
        $order = $this->order(paid: false);
        $fulfilment = $this->fulfilment($order);

        $outcome = app(FulfilmentService::class)->submit($fulfilment);

        $this->assertSame('refused', $outcome->status);
        $this->assertSame(Fulfilment::STATE_PENDING, $fulfilment->fresh()->state);
    }

    #[Test]
    public function a_refunded_order_is_never_submitted(): void
    {
        $order = $this->order();
        $order->forceFill(['payment_state' => Order::PAYMENT_REFUNDED])->save();

        $outcome = app(FulfilmentService::class)->submit($this->fulfilment($order));

        $this->assertSame('refused', $outcome->status);
    }

    #[Test]
    public function a_cancelled_order_is_never_submitted(): void
    {
        $order = $this->order();
        $order->forceFill(['cancelled_at' => now()])->save();

        $this->assertSame('refused', app(FulfilmentService::class)->submit($this->fulfilment($order))->status);
    }

    #[Test]
    public function a_demo_order_cannot_be_fulfilled_through_a_live_supplier(): void
    {
        $this->supplier->forceFill(['mode' => Supplier::MODE_LIVE])->save();

        $order = $this->order();
        $order->forceFill(['is_demo' => true])->save();

        $outcome = app(FulfilmentService::class)->submit($this->fulfilment($order));

        $this->assertSame('refused', $outcome->status);
        $this->assertStringContainsString('demo order', $outcome->message);
        $this->assertDatabaseHas('operational_exceptions', ['type' => 'mixed_mode_blocked']);
    }

    #[Test]
    public function a_real_order_is_not_quietly_fulfilled_by_the_demo_adapter(): void
    {
        // Supplier stays in demo mode while the order is real money.
        $order = $this->order();
        $order->forceFill(['is_demo' => false])->save();

        $outcome = app(FulfilmentService::class)->submit($this->fulfilment($order));

        $this->assertSame('refused', $outcome->status);
        $this->assertStringContainsString('demo mode', $outcome->message);
    }

    #[Test]
    public function a_timeout_marks_the_order_for_reconciliation_and_never_retries_blindly(): void
    {
        $order = $this->order(reference: '-TIMEOUT');
        $order->forceFill(['is_demo' => true])->save();
        $fulfilment = $this->fulfilment($order);

        $outcome = app(FulfilmentService::class)->submit($fulfilment);

        $this->assertSame('needs_reconciliation', $outcome->status);
        $this->assertSame(Fulfilment::STATE_NEEDS_RECONCILIATION, $fulfilment->fresh()->state);
        $this->assertSame(Order::SUPPLIER_RECONCILING, $order->fresh()->supplier_order_state);

        $exception = \App\Models\OperationalException::where('type', 'supplier_timeout')->firstOrFail();
        $this->assertSame('critical', $exception->severity);
        $this->assertStringContainsString('Do NOT resubmit', (string) $exception->suggested_action);
    }

    #[Test]
    public function reconciliation_finds_the_existing_order_instead_of_creating_a_second_one(): void
    {
        $order = $this->order(reference: '-TIMEOUT');
        $order->forceFill(['is_demo' => true])->save();
        $fulfilment = $this->fulfilment($order);

        $service = app(FulfilmentService::class);

        // The first attempt times out, but the demo supplier did record it.
        $service->submit($fulfilment);
        $this->assertTrue($fulfilment->fresh()->needsReconciliation());

        // Submitting again must reconcile rather than create a duplicate.
        $outcome = $service->submit($fulfilment->fresh());

        $this->assertSame('reconciled_found', $outcome->status);
        $this->assertSame(Fulfilment::STATE_CONFIRMED, $fulfilment->fresh()->state);
        $this->assertSame(Order::SUPPLIER_CONFIRMED, $order->fresh()->supplier_order_state);

        // Exactly one fulfilment, and one supplier order id.
        $this->assertSame(1, $order->fresh()->fulfilments()->count());
        $this->assertSame(1, Fulfilment::whereNotNull('supplier_order_id')->count());
    }

    #[Test]
    public function a_confirmed_fulfilment_is_not_submitted_twice(): void
    {
        $order = $this->order();
        $order->forceFill(['is_demo' => true])->save();
        $fulfilment = $this->fulfilment($order);

        $service = app(FulfilmentService::class);

        $first = $service->submit($fulfilment);
        $this->assertSame('submitted', $first->status);

        $second = $service->submit($fulfilment->fresh());
        $this->assertSame('already_done', $second->status);

        $this->assertSame(1, $order->fresh()->stateEvents()
            ->where('machine', 'supplier_order')->where('to_state', Order::SUPPLIER_CONFIRMED)->count());
    }

    #[Test]
    public function the_supplier_is_never_paid_twice_for_the_same_fulfilment(): void
    {
        $order = $this->order();
        $order->forceFill(['is_demo' => true])->save();
        $fulfilment = $this->fulfilment($order);

        app(FulfilmentService::class)->submit($fulfilment);
        $fulfilment = $fulfilment->fresh();

        $actor = $this->owner();
        $service = app(SupplierPaymentService::class);

        $first = $service->pay($fulfilment, $actor, chargeAuthorised: true);
        $this->assertTrue($first->isSettled());

        // A second attempt short circuits on the existing settled payment.
        $second = $service->pay($fulfilment->fresh(), $actor, chargeAuthorised: true);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SupplierPayment::where('fulfilment_id', $fulfilment->id)->count());
    }

    #[Test]
    public function paying_the_supplier_requires_explicit_authorisation(): void
    {
        $order = $this->order();
        $order->forceFill(['is_demo' => true])->save();
        $fulfilment = $this->fulfilment($order);
        app(FulfilmentService::class)->submit($fulfilment);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicitly authorised');

        app(SupplierPaymentService::class)->pay($fulfilment->fresh(), $this->owner(), chargeAuthorised: false);
    }

    #[Test]
    public function an_insufficient_supplier_balance_becomes_an_exception_not_a_silent_failure(): void
    {
        $order = $this->order(reference: '-NOBAL');
        $order->forceFill(['is_demo' => true])->save();
        $fulfilment = $this->fulfilment($order);

        app(FulfilmentService::class)->submit($fulfilment);

        $payment = app(SupplierPaymentService::class)->pay($fulfilment->fresh(), $this->owner(), chargeAuthorised: true);

        $this->assertSame(SupplierPayment::STATUS_INSUFFICIENT, $payment->status);
        $this->assertSame(Order::SUPPLIER_PAY_INSUFFICIENT, $order->fresh()->supplier_payment_state);
        $this->assertDatabaseHas('operational_exceptions', ['type' => 'insufficient_balance']);
    }

    #[Test]
    public function customer_payment_state_is_independent_of_supplier_payment_state(): void
    {
        $order = $this->order();
        $order->forceFill(['is_demo' => true])->save();

        // The customer has paid.
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_state);

        // The supplier has not been paid, and nothing implies otherwise.
        $this->assertSame(Order::SUPPLIER_PAY_NOT_PAID, $order->supplier_payment_state);
        $this->assertSame(Order::SUPPLIER_NOT_SUBMITTED, $order->supplier_order_state);
    }

    /* ---------------------------------------------------------------- */

    private function owner(): User
    {
        $user = User::factory()->create(['is_staff' => true]);
        $user->syncRoles(['owner']);

        return $user;
    }

    private function order(bool $paid = true, string $reference = ''): Order
    {
        $variant = $this->variant();

        $order = Order::create([
            'number' => 'PS-TEST-'.strtoupper(substr(uniqid(), -5)).$reference,
            'market_id' => Market::default()->id,
            'currency' => 'USD',
            'is_guest' => true,
            'email' => 'buyer@example.test',
            'payment_state' => $paid ? Order::PAYMENT_PAID : Order::PAYMENT_PENDING,
            'approval_state' => $paid ? Order::APPROVAL_APPROVED : Order::APPROVAL_AWAITING,
            'supplier_order_state' => Order::SUPPLIER_NOT_SUBMITTED,
            'supplier_payment_state' => Order::SUPPLIER_PAY_NOT_PAID,
            'shipment_state' => Order::SHIPMENT_NONE,
            'subtotal_minor' => 2500,
            'total_minor' => 2500,
            'shipping_address' => [
                'first_name' => 'Ada', 'last_name' => 'Lovelace',
                'line1' => '1 Test Street', 'city' => 'Newark', 'state' => 'NJ',
                'postal_code' => '07101', 'country_code' => 'US', 'phone' => '5551234567',
            ],
            'placed_at' => now(),
            'paid_at' => $paid ? now() : null,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'sku' => $variant->sku,
            'name' => 'Test item',
            'quantity' => 1,
            'unit_price_minor' => 2500,
            'line_subtotal_minor' => 2500,
            'currency' => 'USD',
            'supplier_variant_id' => $variant->supplier_variant_id,
            'supplier_cost_minor' => 1000,
            'supplier_cost_currency' => 'USD',
            'warehouse_id' => $this->warehouse->id,
        ]);

        return $order->fresh();
    }

    private function fulfilment(Order $order): Fulfilment
    {
        $fulfilment = Fulfilment::create([
            'order_id' => $order->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'internal_reference' => $order->number.'-P1',
            'idempotency_key' => hash('sha256', $order->number.'-P1'),
            'state' => Fulfilment::STATE_PENDING,
            'mode' => $this->supplier->mode,
            'is_demo' => $order->is_demo,
            'currency' => 'USD',
        ]);

        foreach ($order->items as $item) {
            $fulfilment->items()->create(['order_item_id' => $item->id, 'quantity' => $item->quantity]);
        }

        return $fulfilment;
    }

    private function variant(): ProductVariant
    {
        $product = Product::create([
            'slug' => 'p-'.uniqid(),
            'name' => 'Test product',
            'status' => Product::STATUS_PUBLISHED,
            'supplier_id' => $this->supplier->id,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.uniqid(),
            'currency' => 'USD',
            'supplier_cost_minor' => 1000,
            'supplier_cost_currency' => 'USD',
            'supplier_variant_id' => 'DEMO-V-1001-S',
            'weight_grams' => 200,
            'is_active' => true,
        ]);

        WarehouseStock::create([
            'warehouse_id' => $this->warehouse->id,
            'product_variant_id' => $variant->id,
            'supplier_variant_id' => $variant->supplier_variant_id,
            'quantity' => 50,
            'quantity_known' => true,
            'synced_at' => now(),
            'stale_after' => now()->addDay(),
        ]);

        return $variant;
    }
}
