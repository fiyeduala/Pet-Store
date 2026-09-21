<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payments\Contracts\PaymentGatewayAdapter;
use App\Domain\Payments\DTO\CaptureResult;
use App\Domain\Payments\DTO\PaymentIntent;
use App\Domain\Payments\DTO\RefundResult;
use App\Domain\Payments\DTO\WebhookVerification;
use App\Domain\Payments\PaymentGatewayRegistry;
use App\Domain\Payments\PaymentService;
use App\Models\Market;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentGateway;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gateway authenticity, duplicate delivery, and out-of-order delivery.
 */
class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private PaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->gateway = PaymentGateway::where('code', 'demo')->firstOrFail();
        $this->order = $this->makeOrder();
    }

    #[Test]
    public function an_unverified_webhook_is_recorded_but_never_applied(): void
    {
        $this->fakeGateway(fn () => WebhookVerification::rejected('Signature mismatch.'));

        $payment = $this->makePayment();

        $event = app(PaymentService::class)->handleWebhook('demo', $this->request([]));

        $this->assertFalse($event->signature_verified);
        $this->assertSame('ignored', $event->status);

        // Crucially, the order is NOT paid.
        $this->assertSame(Order::PAYMENT_PENDING, $this->order->fresh()->payment_state);
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    #[Test]
    public function a_verified_success_webhook_marks_the_order_paid(): void
    {
        $payment = $this->makePayment();
        $this->fakeGateway(fn () => $this->verification('evt-1', 'PAYMENT.CAPTURE.COMPLETED', now()));

        app(PaymentService::class)->handleWebhook('demo', $this->request([]));

        $this->assertSame(Order::PAYMENT_PAID, $this->order->fresh()->payment_state);
        $this->assertSame(Payment::STATUS_CAPTURED, $payment->fresh()->status);

        // Paying does NOT imply the supplier was ordered from or paid.
        $this->assertSame(Order::SUPPLIER_NOT_SUBMITTED, $this->order->fresh()->supplier_order_state);
        $this->assertSame(Order::SUPPLIER_PAY_NOT_PAID, $this->order->fresh()->supplier_payment_state);
        $this->assertSame(Order::APPROVAL_AWAITING, $this->order->fresh()->approval_state);
    }

    #[Test]
    public function a_duplicate_webhook_is_stored_once_and_applied_once(): void
    {
        $this->makePayment();
        $this->fakeGateway(fn () => $this->verification('evt-dup', 'PAYMENT.CAPTURE.COMPLETED', now()));

        $service = app(PaymentService::class);

        $first = $service->handleWebhook('demo', $this->request([]));
        $second = $service->handleWebhook('demo', $this->request([]));
        $third = $service->handleWebhook('demo', $this->request([]));

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame(1, PaymentEvent::where('event_id', 'evt-dup')->count());

        // And the order was only paid once.
        $this->assertSame(1, $this->order->fresh()->stateEvents()
            ->where('machine', 'payment')->where('to_state', Order::PAYMENT_PAID)->count());
    }

    #[Test]
    public function an_out_of_order_webhook_cannot_undo_a_newer_one(): void
    {
        $payment = $this->makePayment();

        // The newer "captured" event arrives first.
        $this->fakeGateway(fn () => $this->verification('evt-new', 'PAYMENT.CAPTURE.COMPLETED', now()));
        app(PaymentService::class)->handleWebhook('demo', $this->request([]));
        $this->assertSame(Payment::STATUS_CAPTURED, $payment->fresh()->status);

        // Then a stale "denied" event, stamped earlier, is delivered late.
        $this->fakeGateway(fn () => $this->verification('evt-old', 'PAYMENT.CAPTURE.DENIED', now()->subHour()));
        $late = app(PaymentService::class)->handleWebhook('demo', $this->request([]));

        $this->assertSame('ignored', $late->status);
        $this->assertStringContainsString('newer event', (string) $late->note);

        // The payment is still captured; the stale event did not undo it.
        $this->assertSame(Payment::STATUS_CAPTURED, $payment->fresh()->status);
        $this->assertSame(Order::PAYMENT_PAID, $this->order->fresh()->payment_state);
    }

    #[Test]
    public function a_webhook_whose_amount_disagrees_raises_an_exception_and_does_not_pay(): void
    {
        $this->makePayment();

        $this->fakeGateway(fn () => new WebhookVerification(
            verified: true,
            eventId: 'evt-wrong-amount',
            eventType: 'PAYMENT.CAPTURE.COMPLETED',
            providerReference: 'ref-1',
            orderReference: $this->order->number,
            // The provider says a different amount than the order total.
            amount: Money::ofMinor(500, 'USD'),
            occurredAt: now(),
        ));

        $event = app(PaymentService::class)->handleWebhook('demo', $this->request([]));

        $this->assertSame('failed', $event->status);
        $this->assertSame(Order::PAYMENT_PENDING, $this->order->fresh()->payment_state);
        $this->assertDatabaseHas('operational_exceptions', [
            'type' => 'payment_mismatch',
            'order_id' => $this->order->id,
        ]);
    }

    #[Test]
    public function a_webhook_in_the_wrong_currency_is_refused(): void
    {
        $this->makePayment();

        $this->fakeGateway(fn () => new WebhookVerification(
            verified: true,
            eventId: 'evt-wrong-currency',
            eventType: 'PAYMENT.CAPTURE.COMPLETED',
            orderReference: $this->order->number,
            // Right number, wrong currency: 2500 NGN is not 2500 USD.
            amount: Money::ofMinor(2500, 'NGN'),
            occurredAt: now(),
        ));

        $event = app(PaymentService::class)->handleWebhook('demo', $this->request([]));

        $this->assertSame('failed', $event->status);
        $this->assertSame(Order::PAYMENT_PENDING, $this->order->fresh()->payment_state);
    }

    #[Test]
    public function a_webhook_for_an_unknown_order_is_ignored_safely(): void
    {
        $this->makePayment();

        $this->fakeGateway(fn () => new WebhookVerification(
            verified: true,
            eventId: 'evt-unknown',
            eventType: 'PAYMENT.CAPTURE.COMPLETED',
            orderReference: 'PS-000000-NOPE',
            occurredAt: now(),
        ));

        $event = app(PaymentService::class)->handleWebhook('demo', $this->request([]));

        $this->assertSame('ignored', $event->status);
        $this->assertSame(Order::PAYMENT_PENDING, $this->order->fresh()->payment_state);
    }

    /* ---------------------------------------------------------------- */

    private function verification(string $id, string $type, $occurredAt): WebhookVerification
    {
        return new WebhookVerification(
            verified: true,
            eventId: $id,
            eventType: $type,
            providerReference: 'ref-'.$id,
            orderReference: $this->order->number,
            amount: Money::ofMinor((int) $this->order->getRawOriginal('total_minor'), $this->order->currency),
            occurredAt: $occurredAt,
        );
    }

    private function request(array $body): Request
    {
        return Request::create('/webhooks/payments/demo', 'POST', [], [], [], [], json_encode($body));
    }

    private function fakeGateway(callable $verify): void
    {
        $adapter = new class($this->gateway, $verify) implements PaymentGatewayAdapter
        {
            public function __construct(private PaymentGateway $g, private $verify) {}

            public function code(): string { return 'demo'; }
            public function gateway(): PaymentGateway { return $this->g; }
            public function isDemo(): bool { return true; }
            public function supportedCurrencies(): array { return ['USD']; }
            public function createIntent(Order $o, Money $a): PaymentIntent { return new PaymentIntent('x', $a); }
            public function capture(Payment $p, string $k): CaptureResult { return new CaptureResult(true, 'captured'); }
            public function fetchStatus(Payment $p): CaptureResult { return new CaptureResult(true, 'captured'); }
            public function refund(Payment $p, Money $a, string $k, ?string $r = null): RefundResult { return new RefundResult(true, 'completed'); }
            public function verifyWebhook(Request $request): WebhookVerification { return ($this->verify)(); }
        };

        $registry = \Mockery::mock(PaymentGatewayRegistry::class)->makePartial();
        $registry->shouldReceive('byCode')->andReturn($adapter);
        $this->app->instance(PaymentGatewayRegistry::class, $registry);

        // PaymentService is a singleton holding the registry, so it must be
        // rebuilt or it keeps a reference to the real one.
        $this->app->forgetInstance(PaymentService::class);
    }

    private function makeOrder(): Order
    {
        return Order::create([
            'number' => 'PS-TEST-'.strtoupper(substr(uniqid(), -5)),
            'market_id' => Market::default()->id,
            'currency' => 'USD',
            'is_guest' => true,
            'email' => 'buyer@example.test',
            'payment_state' => Order::PAYMENT_PENDING,
            'approval_state' => Order::APPROVAL_NOT_REQUIRED,
            'supplier_order_state' => Order::SUPPLIER_NOT_SUBMITTED,
            'supplier_payment_state' => Order::SUPPLIER_PAY_NOT_PAID,
            'shipment_state' => Order::SHIPMENT_NONE,
            'total_minor' => 2500,
            'subtotal_minor' => 2500,
            'shipping_address' => ['country_code' => 'US', 'postal_code' => '10001'],
            'placed_at' => now(),
        ]);
    }

    private function makePayment(): Payment
    {
        return Payment::create([
            'order_id' => $this->order->id,
            'gateway_code' => 'demo',
            'mode' => 'demo',
            'is_demo' => true,
            'status' => Payment::STATUS_PENDING,
            'provider_order_id' => 'po-1',
            'idempotency_key' => 'cap-'.$this->order->number,
            'amount_minor' => 2500,
            'currency' => 'USD',
        ]);
    }
}
