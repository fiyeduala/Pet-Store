<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Shared\MixedModeException;
use App\Domain\Shared\ModeGuard;
use App\Models\Market;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Demo money and real money must never touch.
 */
class DemoIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    #[Test]
    public function a_demo_gateway_marks_the_order_as_demo(): void
    {
        $guard = app(ModeGuard::class);

        $this->assertTrue($guard->orderIsDemoFor(PaymentGateway::where('code', 'demo')->first()));
    }

    #[Test]
    public function a_sandbox_gateway_also_marks_the_order_as_demo(): void
    {
        $gateway = PaymentGateway::where('code', 'paypal')->first();
        $gateway->forceFill(['mode' => PaymentGateway::MODE_SANDBOX])->save();

        // Sandbox money is not real money either.
        $this->assertTrue(app(ModeGuard::class)->orderIsDemoFor($gateway));
    }

    #[Test]
    public function only_a_live_gateway_produces_a_real_order(): void
    {
        $gateway = PaymentGateway::where('code', 'paypal')->first();
        $gateway->forceFill(['mode' => PaymentGateway::MODE_LIVE])->save();

        $this->assertFalse(app(ModeGuard::class)->orderIsDemoFor($gateway));
    }

    #[Test]
    public function a_demo_order_cannot_be_fulfilled_live(): void
    {
        $supplier = Supplier::cj();
        $supplier->forceFill(['mode' => Supplier::MODE_LIVE])->save();

        $order = $this->order(isDemo: true);

        $this->expectException(MixedModeException::class);
        app(ModeGuard::class)->assertFulfilmentAllowed($order, $supplier->fresh());
    }

    #[Test]
    public function a_real_order_cannot_be_fulfilled_by_a_demo_supplier(): void
    {
        $order = $this->order(isDemo: false);

        $this->expectException(MixedModeException::class);
        app(ModeGuard::class)->assertFulfilmentAllowed($order, Supplier::cj());
    }

    #[Test]
    public function matching_modes_are_allowed(): void
    {
        $order = $this->order(isDemo: true);

        app(ModeGuard::class)->assertFulfilmentAllowed($order, Supplier::cj());

        $this->assertTrue(true, 'No exception was thrown for a demo order on a demo supplier.');
    }

    #[Test]
    public function demo_orders_are_excluded_from_real_money_reporting(): void
    {
        $this->order(isDemo: true);
        $this->order(isDemo: true);
        $real = $this->order(isDemo: false);

        $this->assertSame(3, Order::count());
        $this->assertSame(1, Order::realMoney()->count());
        $this->assertSame($real->id, Order::realMoney()->first()->id);
    }

    #[Test]
    public function a_live_gateway_without_verification_cannot_take_a_payment(): void
    {
        $gateway = PaymentGateway::where('code', 'paypal')->first();

        $gateway->forceFill([
            'is_enabled' => true,
            'mode' => PaymentGateway::MODE_LIVE,
            'is_configured' => true,
            'is_verified' => false,
        ])->save();

        $this->assertFalse($gateway->isLiveReady());
        $this->assertStringContainsString('not been recorded as verified', (string) $gateway->blockingReason('USD'));
    }

    #[Test]
    public function a_gateway_that_cannot_take_the_currency_is_not_offered(): void
    {
        $gateway = PaymentGateway::where('code', 'paystack')->first();

        $gateway->forceFill([
            'is_enabled' => true,
            'mode' => PaymentGateway::MODE_LIVE,
            'is_configured' => true,
            'is_verified' => true,
            // NGN only: this account cannot take USD.
            'supported_currencies' => ['NGN'],
        ])->save();

        $reason = $gateway->blockingReason('USD');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('USD', $reason);

        // And it is genuinely absent from the offered list, not just flagged.
        $available = app(\App\Domain\Payments\PaymentGatewayRegistry::class)->availableFor('USD');
        $this->assertFalse($available->contains('code', 'paystack'));
    }

    #[Test]
    public function checkout_reports_clearly_when_no_method_can_take_the_currency(): void
    {
        PaymentGateway::query()->update(['is_enabled' => false]);

        $reason = app(\App\Domain\Payments\PaymentGatewayRegistry::class)->unavailableReason('USD');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('USD', $reason);
    }

    #[Test]
    public function the_demo_seeder_refuses_to_run_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['petstore.demo.seeding_enabled' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to seed demo catalogue data in production');

        (new \Database\Seeders\DemoCatalogueSeeder)->run();
    }

    private function order(bool $isDemo): Order
    {
        return Order::create([
            'number' => 'PS-TEST-'.strtoupper(substr(uniqid(), -6)),
            'market_id' => Market::default()->id,
            'currency' => 'USD',
            'is_guest' => true,
            'email' => 'buyer@example.test',
            'payment_state' => Order::PAYMENT_PAID,
            'approval_state' => Order::APPROVAL_APPROVED,
            'supplier_order_state' => Order::SUPPLIER_NOT_SUBMITTED,
            'supplier_payment_state' => Order::SUPPLIER_PAY_NOT_PAID,
            'shipment_state' => Order::SHIPMENT_NONE,
            'subtotal_minor' => 2500,
            'total_minor' => 2500,
            'is_demo' => $isDemo,
            'shipping_address' => ['country_code' => 'US', 'postal_code' => '10001'],
            'placed_at' => now(),
            'paid_at' => now(),
        ]);
    }
}
