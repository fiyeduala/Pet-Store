<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Contracts\PaymentGatewayAdapter;
use App\Domain\Payments\DTO\CaptureResult;
use App\Domain\Payments\DTO\PaymentIntent;
use App\Domain\Payments\DTO\RefundResult;
use App\Domain\Payments\DTO\WebhookVerification;
use App\Domain\Payments\Exceptions\GatewayNotAvailable;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Support\Money\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Paystack transactions API.
 *
 * Paystack works in integer minor units natively, which suits this
 * application's money handling.
 *
 * USD SUPPORT IS NOT ASSUMED. Many Paystack accounts are NGN-only. The
 * currencies this gateway may accept are whatever an administrator recorded
 * on the gateway after confirming with Paystack; if USD is not among them
 * the method is simply not offered for a USD order. It is never silently
 * charged in NGN instead.
 *
 * Verification status: implemented against Paystack's documented REST API.
 * Not exercised against a live account from this build environment.
 */
class PaystackGateway implements PaymentGatewayAdapter
{
    public function __construct(private readonly PaymentGateway $model) {}

    public function code(): string
    {
        return 'paystack';
    }

    public function gateway(): PaymentGateway
    {
        return $this->model;
    }

    public function isDemo(): bool
    {
        return $this->model->mode === PaymentGateway::MODE_DEMO;
    }

    public function supportedCurrencies(): array
    {
        return array_map('strtoupper', $this->model->supported_currencies ?? []);
    }

    public function createIntent(Order $order, Money $amount): PaymentIntent
    {
        $this->assertUsable($amount->currency);

        $response = $this->client()->post('/transaction/initialize', [
            'email' => $order->email,
            // Paystack expects the amount in minor units, which is what we hold.
            'amount' => $amount->minor,
            'currency' => $amount->currency,
            'reference' => $this->reference($order),
            'callback_url' => route('checkout.payment.return', ['gateway' => 'paystack', 'order' => $order->number]),
            'metadata' => ['order_number' => $order->number],
        ]);

        if ($response->failed() || $response->json('status') !== true) {
            throw new PaymentException('Paystack rejected the transaction: '.(string) $response->json('message', 'unknown error'));
        }

        return new PaymentIntent(
            providerOrderId: (string) $response->json('data.reference'),
            amount: $amount,
            approvalUrl: (string) $response->json('data.authorization_url'),
            clientToken: $this->model->public_client_id,
            raw: ['reference' => $response->json('data.reference')],
        );
    }

    /**
     * Paystack captures at authorisation, so "capture" is a server-side
     * verification of what actually happened. The browser redirect is never
     * treated as proof.
     */
    public function capture(Payment $payment, string $idempotencyKey): CaptureResult
    {
        return $this->fetchStatus($payment);
    }

    public function fetchStatus(Payment $payment): CaptureResult
    {
        $reference = $payment->provider_order_id;

        if (blank($reference)) {
            return new CaptureResult(false, 'unknown', failureReason: 'No Paystack reference recorded.');
        }

        $response = $this->client()->get('/transaction/verify/'.urlencode($reference));

        if ($response->failed() || $response->json('status') !== true) {
            return new CaptureResult(
                captured: false,
                status: 'unknown',
                failureReason: (string) $response->json('message', 'Paystack verification failed.'),
            );
        }

        $data = (array) $response->json('data');
        $status = strtolower((string) ($data['status'] ?? 'unknown'));
        $currency = strtoupper((string) ($data['currency'] ?? $payment->currency));

        return new CaptureResult(
            captured: $status === 'success',
            status: $status,
            providerReference: isset($data['id']) ? (string) $data['id'] : $reference,
            amount: isset($data['amount']) ? Money::ofMinor((int) $data['amount'], $currency) : null,
            fee: isset($data['fees']) ? Money::ofMinor((int) $data['fees'], $currency) : null,
            failureReason: $status === 'success' ? null : (string) ($data['gateway_response'] ?? "Paystack reported {$status}."),
            raw: $this->redact($data),
        );
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): RefundResult
    {
        $response = $this->client()->post('/refund', [
            'transaction' => $payment->provider_order_id,
            'amount' => $amount->minor,
            'currency' => $amount->currency,
            'merchant_note' => $reason,
        ]);

        $body = (array) $response->json();

        if ($response->failed() || ($body['status'] ?? false) !== true) {
            $message = (string) ($body['message'] ?? 'Paystack refused the refund.');

            // Paystack reports an already-refunded transaction as an error;
            // treat that as the refund having happened, not as a new failure.
            if (str_contains(strtolower($message), 'already') && str_contains(strtolower($message), 'refund')) {
                return new RefundResult(true, 'completed', raw: $body);
            }

            return new RefundResult(false, 'failed', failureReason: $message, raw: $body);
        }

        $status = strtolower((string) data_get($body, 'data.status', 'pending'));

        return new RefundResult(
            accepted: true,
            status: in_array($status, ['processed', 'success'], true) ? 'completed' : 'processing',
            providerReference: (string) data_get($body, 'data.id'),
            amount: $amount,
            raw: $this->redact((array) data_get($body, 'data', [])),
        );
    }

    /**
     * Paystack signs the raw body with HMAC-SHA512 using the secret key.
     */
    public function verifyWebhook(Request $request): WebhookVerification
    {
        $secret = $this->secret('secret_key');

        if (blank($secret)) {
            return WebhookVerification::rejected('No Paystack secret key is configured; cannot verify authenticity.');
        }

        $signature = (string) $request->header('x-paystack-signature');
        $expected = hash_hmac('sha512', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return WebhookVerification::rejected('Paystack signature mismatch.');
        }

        $payload = $request->json()->all();
        $data = (array) ($payload['data'] ?? []);
        $currency = strtoupper((string) ($data['currency'] ?? 'NGN'));

        return new WebhookVerification(
            verified: true,
            // Paystack does not send a dedicated event id, so the reference
            // plus event type is what makes a redelivery detectable.
            eventId: ($payload['event'] ?? 'event').':'.($data['reference'] ?? ''),
            eventType: isset($payload['event']) ? (string) $payload['event'] : null,
            providerReference: isset($data['id']) ? (string) $data['id'] : null,
            orderReference: data_get($data, 'metadata.order_number'),
            amount: isset($data['amount']) ? Money::ofMinor((int) $data['amount'], $currency) : null,
            merchantId: data_get($data, 'domain'),
            occurredAt: isset($data['paid_at']) ? Carbon::parse((string) $data['paid_at']) : null,
            payload: $this->redact($payload),
        );
    }

    /* ------------------------------------------------------------------ */

    private function client(): PendingRequest
    {
        $secret = $this->secret('secret_key');

        if (blank($secret)) {
            throw new GatewayNotAvailable('Paystack credentials are not configured.');
        }

        return Http::baseUrl((string) config('petstore.payments.paystack.base_url'))
            ->withToken($secret)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10);
    }

    private function secret(string $key): ?string
    {
        $value = config("petstore.payments.paystack.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function reference(Order $order): string
    {
        return $order->number.'-'.substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private function assertUsable(string $currency): void
    {
        $reason = $this->model->blockingReason($currency);

        if ($reason !== null) {
            throw new GatewayNotAvailable("Paystack cannot take this payment: {$reason}");
        }
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function redact(array $data): array
    {
        unset($data['authorization'], $data['customer'], $data['log']);

        return $data;
    }
}
