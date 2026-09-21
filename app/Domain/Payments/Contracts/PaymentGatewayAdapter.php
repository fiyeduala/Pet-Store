<?php

declare(strict_types=1);

namespace App\Domain\Payments\Contracts;

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
 * A replaceable payment gateway.
 *
 * Rules every implementation must honour:
 *  - Creation and capture happen server side. A browser redirect is never
 *    sufficient evidence that money moved.
 *  - Secrets are read from server-side configuration only.
 *  - Refunds and captures carry an idempotency key so a retry cannot
 *    double-charge or double-refund.
 */
interface PaymentGatewayAdapter
{
    public function code(): string;

    public function gateway(): PaymentGateway;

    /**
     * Currencies this gateway is configured to accept.
     *
     * @return array<int, string>
     */
    public function supportedCurrencies(): array;

    /**
     * Create the payment at the provider and return what the browser needs.
     */
    public function createIntent(Order $order, Money $amount): PaymentIntent;

    /**
     * Capture server side and confirm what actually happened.
     */
    public function capture(Payment $payment, string $idempotencyKey): CaptureResult;

    /**
     * Read the authoritative current state from the provider.
     */
    public function fetchStatus(Payment $payment): CaptureResult;

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): RefundResult;

    /**
     * Authenticate an inbound notification.
     */
    public function verifyWebhook(Request $request): WebhookVerification;

    public function isDemo(): bool;
}
