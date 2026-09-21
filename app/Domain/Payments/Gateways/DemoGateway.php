<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Contracts\PaymentGatewayAdapter;
use App\Domain\Payments\DTO\CaptureResult;
use App\Domain\Payments\DTO\PaymentIntent;
use App\Domain\Payments\DTO\RefundResult;
use App\Domain\Payments\DTO\WebhookVerification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Support\Money\Money;
use Illuminate\Http\Request;

/**
 * Simulated payments for demos and automated tests.
 *
 * No money ever moves. Everything it produces is stamped `is_demo`, and
 * ModeGuard prevents an order settled here from ever reaching a live
 * supplier. The storefront labels it unmistakably.
 *
 * Outcomes are chosen deterministically from the order number so a
 * demonstration can reproduce a decline or a pending payment on cue:
 *   ...-DECLINE  → declined
 *   ...-PENDING  → stays pending
 *   anything else → succeeds
 */
class DemoGateway implements PaymentGatewayAdapter
{
    public function __construct(private readonly PaymentGateway $model) {}

    public function code(): string
    {
        return 'demo';
    }

    public function gateway(): PaymentGateway
    {
        return $this->model;
    }

    public function isDemo(): bool
    {
        return true;
    }

    public function supportedCurrencies(): array
    {
        // The simulator can present any currency because it charges none.
        return $this->model->supported_currencies ?: ['USD'];
    }

    public function createIntent(Order $order, Money $amount): PaymentIntent
    {
        return new PaymentIntent(
            providerOrderId: 'DEMO-'.strtoupper(substr(hash('sha256', $order->number), 0, 16)),
            amount: $amount,
            approvalUrl: route('checkout.demo.confirm', ['order' => $order->number]),
            clientToken: null,
            raw: ['demo' => true, 'scenario' => $this->scenario($order->number)],
        );
    }

    public function capture(Payment $payment, string $idempotencyKey): CaptureResult
    {
        $scenario = $this->scenario((string) $payment->order?->number);

        return match ($scenario) {
            'decline' => new CaptureResult(
                captured: false,
                status: 'failed',
                failureReason: 'Demo: the simulated payment was declined.',
                raw: ['demo' => true],
            ),
            'pending' => new CaptureResult(
                captured: false,
                status: 'pending',
                failureReason: 'Demo: the simulated payment is still pending.',
                raw: ['demo' => true],
            ),
            default => new CaptureResult(
                captured: true,
                status: 'captured',
                providerReference: 'DEMO-CAP-'.substr(hash('sha256', $idempotencyKey), 0, 16),
                amount: $payment->amount_minor,
                fee: Money::zero($payment->currency),
                raw: ['demo' => true],
            ),
        };
    }

    public function fetchStatus(Payment $payment): CaptureResult
    {
        return $this->capture($payment, (string) $payment->idempotency_key);
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): RefundResult
    {
        return new RefundResult(
            accepted: true,
            status: 'completed',
            providerReference: 'DEMO-REF-'.substr(hash('sha256', $idempotencyKey), 0, 16),
            amount: $amount,
            raw: ['demo' => true, 'reason' => $reason],
        );
    }

    /**
     * The demo gateway accepts no inbound webhooks. Anything arriving at a
     * demo webhook endpoint is rejected rather than trusted.
     */
    public function verifyWebhook(Request $request): WebhookVerification
    {
        return WebhookVerification::rejected('The demo gateway does not accept webhooks.');
    }

    private function scenario(string $orderNumber): string
    {
        $upper = strtoupper($orderNumber);

        return match (true) {
            str_ends_with($upper, '-DECLINE') => 'decline',
            str_ends_with($upper, '-PENDING') => 'pending',
            default => 'success',
        };
    }
}
