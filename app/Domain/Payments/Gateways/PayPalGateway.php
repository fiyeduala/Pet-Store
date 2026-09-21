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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * PayPal Orders v2 + Payments v2.
 *
 * MERCHANT ELIGIBILITY IS NOT ASSUMED. Having API credentials that work in
 * sandbox proves nothing about whether the live account may accept
 * commercial Checkout payments in a given currency. That is recorded by an
 * administrator on the gateway record (`is_verified`) after checking with
 * PayPal, and `isLiveReady()` gates live charges on it.
 *
 * Verification status: implemented against PayPal's documented v2 REST
 * contract. Not exercised against a live merchant account from this build
 * environment. See docs/integration-notes.md.
 */
class PayPalGateway implements PaymentGatewayAdapter
{
    public function __construct(private readonly PaymentGateway $model) {}

    public function code(): string
    {
        return 'paypal';
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

    /* ------------------------------------------------------------------ */

    public function createIntent(Order $order, Money $amount): PaymentIntent
    {
        $this->assertUsable($amount->currency);

        $response = $this->client()
            // PayPal's own idempotency header, so a retried create does not
            // produce two orders.
            ->withHeaders(['PayPal-Request-Id' => 'create-'.$order->number])
            ->post('/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    // Our order number travels with the payment so a webhook
                    // can be matched back without trusting the browser.
                    'reference_id' => $order->number,
                    'custom_id' => $order->number,
                    'invoice_id' => $order->number,
                    'amount' => [
                        'currency_code' => $amount->currency,
                        'value' => $amount->toDecimalString(),
                    ],
                ]],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'shipping_preference' => 'NO_SHIPPING',
                            'user_action' => 'PAY_NOW',
                            'return_url' => route('checkout.payment.return', ['gateway' => 'paypal', 'order' => $order->number]),
                            'cancel_url' => route('checkout.payment.cancel', ['gateway' => 'paypal', 'order' => $order->number]),
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new PaymentException('PayPal rejected the order creation: '.$this->errorMessage($response->json()));
        }

        $body = $response->json();
        $approval = null;

        foreach ((array) ($body['links'] ?? []) as $link) {
            if (($link['rel'] ?? null) === 'payer-action' || ($link['rel'] ?? null) === 'approve') {
                $approval = $link['href'];
                break;
            }
        }

        return new PaymentIntent(
            providerOrderId: (string) $body['id'],
            amount: $amount,
            approvalUrl: $approval,
            // Only the public client id ever reaches the browser.
            clientToken: $this->model->public_client_id,
            raw: $this->redact($body),
        );
    }

    public function capture(Payment $payment, string $idempotencyKey): CaptureResult
    {
        $this->assertUsable($payment->currency);

        $response = $this->client()
            ->withHeaders(['PayPal-Request-Id' => $idempotencyKey])
            ->post("/v2/checkout/orders/{$payment->provider_order_id}/capture", new \stdClass);

        $body = $response->json() ?? [];

        // A 422 with ORDER_ALREADY_CAPTURED means a previous attempt already
        // succeeded. That is success, not failure: re-read and report it.
        if ($response->status() === 422 && $this->hasIssue($body, 'ORDER_ALREADY_CAPTURED')) {
            return $this->fetchStatus($payment);
        }

        if ($response->failed()) {
            return new CaptureResult(
                captured: false,
                status: 'failed',
                failureReason: $this->errorMessage($body),
                raw: $this->redact($body),
            );
        }

        return $this->mapCapture($body, $payment);
    }

    public function fetchStatus(Payment $payment): CaptureResult
    {
        $response = $this->client()->get("/v2/checkout/orders/{$payment->provider_order_id}");

        if ($response->failed()) {
            return new CaptureResult(false, 'unknown', failureReason: $this->errorMessage($response->json()));
        }

        return $this->mapCapture($response->json() ?? [], $payment);
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ?string $reason = null): RefundResult
    {
        if (blank($payment->provider_reference)) {
            return new RefundResult(false, 'failed', failureReason: 'No PayPal capture id is recorded for this payment.');
        }

        $response = $this->client()
            ->withHeaders(['PayPal-Request-Id' => $idempotencyKey])
            ->post("/v2/payments/captures/{$payment->provider_reference}/refund", [
                'amount' => [
                    'currency_code' => $amount->currency,
                    'value' => $amount->toDecimalString(),
                ],
                'note_to_payer' => $reason,
            ]);

        $body = $response->json() ?? [];

        if ($response->status() === 422 && $this->hasIssue($body, 'CAPTURE_FULLY_REFUNDED')) {
            return new RefundResult(true, 'completed', failureReason: null, raw: $this->redact($body));
        }

        if ($response->failed()) {
            return new RefundResult(false, 'failed', failureReason: $this->errorMessage($body), raw: $this->redact($body));
        }

        $status = strtolower((string) ($body['status'] ?? 'pending'));

        return new RefundResult(
            accepted: true,
            status: $status === 'completed' ? 'completed' : 'processing',
            providerReference: isset($body['id']) ? (string) $body['id'] : null,
            amount: $amount,
            raw: $this->redact($body),
        );
    }

    /**
     * Verify a webhook with PayPal's own verification endpoint. We never
     * trust the body alone.
     */
    public function verifyWebhook(Request $request): WebhookVerification
    {
        $payload = $request->json()->all();
        $webhookId = $this->secret('webhook_id');

        if (blank($webhookId)) {
            return WebhookVerification::rejected('No PayPal webhook id is configured; cannot verify authenticity.', $payload);
        }

        try {
            $response = $this->client()->post('/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $webhookId,
                'webhook_event' => $payload,
            ]);
        } catch (Throwable $e) {
            return WebhookVerification::rejected('Could not reach PayPal to verify the webhook: '.$e->getMessage(), $payload);
        }

        if ($response->failed() || strtoupper((string) $response->json('verification_status')) !== 'SUCCESS') {
            return WebhookVerification::rejected('PayPal did not confirm the webhook signature.', $payload);
        }

        $resource = (array) ($payload['resource'] ?? []);
        $amountData = $resource['amount'] ?? null;

        $amount = null;
        if (is_array($amountData) && isset($amountData['value'], $amountData['currency_code'])) {
            $amount = Money::ofDecimalString((string) $amountData['value'], (string) $amountData['currency_code']);
        }

        return new WebhookVerification(
            verified: true,
            eventId: isset($payload['id']) ? (string) $payload['id'] : null,
            eventType: isset($payload['event_type']) ? (string) $payload['event_type'] : null,
            providerReference: isset($resource['id']) ? (string) $resource['id'] : null,
            // custom_id carries OUR order number.
            orderReference: $resource['custom_id'] ?? $resource['invoice_id'] ?? null,
            amount: $amount,
            merchantId: data_get($resource, 'payee.merchant_id'),
            occurredAt: isset($payload['create_time']) ? Carbon::parse((string) $payload['create_time']) : null,
            payload: $this->redact($payload),
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $body
     */
    private function mapCapture(array $body, Payment $payment): CaptureResult
    {
        $status = strtoupper((string) ($body['status'] ?? 'UNKNOWN'));
        $capture = data_get($body, 'purchase_units.0.payments.captures.0');

        $amount = null;
        $fee = null;

        if (is_array($capture)) {
            $value = data_get($capture, 'amount.value');
            $currency = data_get($capture, 'amount.currency_code');

            if (is_scalar($value) && is_string($currency)) {
                $amount = Money::ofDecimalString((string) $value, $currency);
            }

            $feeValue = data_get($capture, 'seller_receivable_breakdown.paypal_fee.value');
            $feeCurrency = data_get($capture, 'seller_receivable_breakdown.paypal_fee.currency_code');

            if (is_scalar($feeValue) && is_string($feeCurrency)) {
                $fee = Money::ofDecimalString((string) $feeValue, $feeCurrency);
            }
        }

        $captured = $status === 'COMPLETED' && strtoupper((string) data_get($capture, 'status', '')) === 'COMPLETED';

        return new CaptureResult(
            captured: $captured,
            status: strtolower($status),
            providerReference: is_array($capture) ? (string) ($capture['id'] ?? '') : null,
            amount: $amount,
            fee: $fee,
            failureReason: $captured ? null : "PayPal reported status {$status}.",
            raw: $this->redact($body),
        );
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10);
    }

    private function accessToken(): string
    {
        $cacheKey = 'paypal.token.'.$this->model->id.'.'.$this->model->mode;

        return Cache::remember($cacheKey, now()->addMinutes(25), function (): string {
            $clientId = $this->secret('client_id');
            $secret = $this->secret('client_secret');

            if (blank($clientId) || blank($secret)) {
                throw new GatewayNotAvailable('PayPal credentials are not configured.');
            }

            $response = Http::baseUrl($this->baseUrl())
                ->asForm()
                ->withBasicAuth($clientId, $secret)
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']);

            if ($response->failed()) {
                throw new GatewayNotAvailable('PayPal rejected the API credentials.');
            }

            return (string) $response->json('access_token');
        });
    }

    private function baseUrl(): string
    {
        return $this->model->mode === PaymentGateway::MODE_LIVE
            ? (string) config('petstore.payments.paypal.live_base_url')
            : (string) config('petstore.payments.paypal.sandbox_base_url');
    }

    /**
     * Secrets come from server-side config only, never from the database
     * settings a browser could reach.
     */
    private function secret(string $key): ?string
    {
        $value = config("petstore.payments.paypal.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function assertUsable(string $currency): void
    {
        $reason = $this->model->blockingReason($currency);

        if ($reason !== null) {
            throw new GatewayNotAvailable("PayPal cannot take this payment: {$reason}");
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function errorMessage(?array $body): string
    {
        if ($body === null) {
            return 'no response body';
        }

        $issue = data_get($body, 'details.0.description') ?? data_get($body, 'details.0.issue');

        return (string) ($issue ?? $body['message'] ?? $body['error_description'] ?? 'unknown error');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function hasIssue(array $body, string $issue): bool
    {
        foreach ((array) ($body['details'] ?? []) as $detail) {
            if (($detail['issue'] ?? null) === $issue) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function redact(array $data): array
    {
        unset($data['payment_source'], $data['payer']);

        return $data;
    }
}
