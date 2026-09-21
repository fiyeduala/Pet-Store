<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\DTO\WebhookVerification;
use App\Domain\Payments\Exceptions\GatewayNotAvailable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentGateway;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates customer payments.
 *
 * The invariants this class exists to hold:
 *   - An order is only ever marked paid from a SERVER-SIDE confirmation.
 *   - A capture is idempotent: the same intent can be retried safely.
 *   - A duplicate webhook is stored once and applied once.
 *   - An out-of-order webhook cannot move the order backwards.
 *   - Amount, currency, merchant and order reference are all checked before
 *     anything is applied.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $registry,
        private readonly OrderStateMachine $states,
    ) {}

    /**
     * Start a payment. Returns the Payment row plus the redirect/approval URL.
     *
     * @return array{payment: Payment, approval_url: ?string, client_token: ?string}
     */
    public function begin(Order $order, PaymentGateway $gateway): array
    {
        $adapter = $this->registry->for($gateway);
        $amount = Money::ofMinor((int) $order->getRawOriginal('total_minor'), $order->currency);

        $blocking = $gateway->blockingReason($order->currency);

        if ($blocking !== null) {
            // Live checkout fails clearly rather than converting silently.
            throw new GatewayNotAvailable("{$gateway->name} cannot take this payment: {$blocking}");
        }

        $intent = $adapter->createIntent($order, $amount);

        $payment = Payment::create([
            'order_id' => $order->id,
            'gateway_code' => $gateway->code,
            'mode' => $gateway->mode,
            'is_demo' => $adapter->isDemo(),
            'status' => Payment::STATUS_PENDING,
            'provider_order_id' => $intent->providerOrderId,
            'idempotency_key' => 'cap-'.$order->number.'-'.Str::random(12),
            'amount_minor' => $amount->minor,
            'currency' => $amount->currency,
            'raw_payload' => $intent->raw,
        ]);

        return [
            'payment' => $payment,
            'approval_url' => $intent->approvalUrl,
            'client_token' => $intent->clientToken,
        ];
    }

    /**
     * Capture (or verify) server side and apply the result to the order.
     *
     * Safe to call more than once: an already-captured payment short
     * circuits instead of charging again.
     */
    public function captureAndApply(Payment $payment): Payment
    {
        if ($payment->isCaptured()) {
            return $payment;
        }

        $adapter = $this->registry->byCode($payment->gateway_code);
        $result = $adapter->capture($payment, (string) $payment->idempotency_key);

        if (! $result->captured) {
            $payment->forceFill([
                'status' => $result->status === 'pending' ? Payment::STATUS_PENDING : Payment::STATUS_FAILED,
                'last_error' => $result->failureReason,
                'raw_payload' => $result->raw,
            ])->save();

            if ($result->status !== 'pending') {
                $this->states->transition($payment->order, 'payment', Order::PAYMENT_FAILED, 'system', null, $result->failureReason);
            }

            return $payment->refresh();
        }

        // The provider's own figures are authoritative. If they disagree with
        // what we asked for, we do not quietly accept the difference.
        $mismatch = $this->amountMismatch($payment, $result->amount);

        DB::transaction(function () use ($payment, $result, $mismatch) {
            $payment->forceFill([
                'status' => Payment::STATUS_CAPTURED,
                'provider_reference' => $result->providerReference,
                'captured_at' => now(),
                'fee_minor' => $result->fee?->currency === $payment->currency ? $result->fee->minor : null,
                'raw_payload' => $result->raw,
                'last_error' => $mismatch,
            ])->save();

            if ($mismatch === null) {
                $this->markOrderPaid($payment);
            }
        });

        if ($mismatch !== null) {
            $this->states->raiseException(
                $payment->order,
                'payment_mismatch',
                'Captured amount does not match the order total',
                $mismatch,
                'Compare the provider dashboard with the order total, then either refund the difference or correct the order.',
                ['payment_id' => $payment->id],
                severity: 'critical',
            );
        }

        return $payment->refresh();
    }

    /**
     * Ingest a provider notification.
     *
     * Every event is recorded first, so a replay is visible even when it is
     * ignored. Only then is it considered for application.
     */
    public function handleWebhook(string $gatewayCode, Request $request): PaymentEvent
    {
        $adapter = $this->registry->byCode($gatewayCode);
        $verification = $adapter->verifyWebhook($request);

        $eventId = $verification->eventId ?: 'unverified:'.hash('sha256', $request->getContent());

        // A duplicate delivery hits this unique key and is returned as-is.
        $existing = PaymentEvent::query()
            ->where('gateway_code', $gatewayCode)
            ->where('event_id', $eventId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $event = PaymentEvent::create([
            'gateway_code' => $gatewayCode,
            'event_id' => $eventId,
            'event_type' => $verification->eventType,
            'signature_verified' => $verification->verified,
            'verification_error' => $verification->error,
            'provider_reference' => $verification->providerReference,
            'occurred_at' => $verification->occurredAt,
            'received_at' => now(),
            'status' => 'received',
            'payload' => $verification->payload,
        ]);

        if (! $verification->verified) {
            Log::warning('Rejected an unverified payment webhook.', [
                'gateway' => $gatewayCode,
                'reason' => $verification->error,
            ]);

            $event->forceFill(['status' => 'ignored', 'note' => 'Signature verification failed.'])->save();

            return $event;
        }

        return $this->applyWebhook($event, $verification);
    }

    private function applyWebhook(PaymentEvent $event, WebhookVerification $v): PaymentEvent
    {
        $order = $v->orderReference !== null
            ? Order::query()->where('number', $v->orderReference)->first()
            : null;

        $payment = $order?->payments()
            ->where('gateway_code', $event->gateway_code)
            ->latest('id')
            ->first();

        if ($payment === null && $v->providerReference !== null) {
            $payment = Payment::query()
                ->where('gateway_code', $event->gateway_code)
                ->where(fn ($q) => $q->where('provider_reference', $v->providerReference)
                    ->orWhere('provider_order_id', $v->providerReference))
                ->first();
        }

        if ($payment === null) {
            $event->forceFill([
                'status' => 'ignored',
                'note' => 'No matching payment for this event.',
                'processed_at' => now(),
            ])->save();

            return $event;
        }

        $event->payment_id = $payment->id;

        // Out-of-order delivery: an event older than one we already applied
        // must not undo the newer state.
        $newer = PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('status', 'processed')
            ->whereNotNull('occurred_at')
            ->when($v->occurredAt !== null, fn ($q) => $q->where('occurred_at', '>', $v->occurredAt))
            ->exists();

        if ($newer) {
            $event->forceFill([
                'status' => 'ignored',
                'note' => 'A newer event for this payment has already been applied.',
                'processed_at' => now(),
            ])->save();

            return $event;
        }

        if ($v->amount !== null) {
            $mismatch = $this->amountMismatch($payment, $v->amount);

            if ($mismatch !== null) {
                $event->forceFill(['status' => 'failed', 'note' => $mismatch, 'processed_at' => now()])->save();

                $this->states->raiseException(
                    $payment->order,
                    'payment_mismatch',
                    'Webhook amount does not match the order',
                    $mismatch,
                    'Verify the transaction in the provider dashboard before dispatching anything.',
                    ['payment_event_id' => $event->id],
                    severity: 'critical',
                );

                return $event;
            }
        }

        $type = strtolower((string) $event->event_type);

        DB::transaction(function () use ($payment, $type, $v, $event) {
            match (true) {
                str_contains($type, 'refund') => $this->applyRefundEvent($payment, $v),
                str_contains($type, 'denied') || str_contains($type, 'failed') => $this->applyFailure($payment),
                str_contains($type, 'completed') || str_contains($type, 'success') || str_contains($type, 'charge.success')
                    => $this->applySuccess($payment, $v),
                default => null,
            };

            $event->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
        });

        return $event->refresh();
    }

    private function applySuccess(Payment $payment, WebhookVerification $v): void
    {
        if ($payment->isCaptured()) {
            return;
        }

        $payment->forceFill([
            'status' => Payment::STATUS_CAPTURED,
            'provider_reference' => $payment->provider_reference ?: $v->providerReference,
            'captured_at' => $payment->captured_at ?? now(),
        ])->save();

        $this->markOrderPaid($payment);
    }

    private function applyFailure(Payment $payment): void
    {
        if ($payment->isCaptured()) {
            return;
        }

        $payment->forceFill(['status' => Payment::STATUS_FAILED])->save();
        $this->states->transition($payment->order, 'payment', Order::PAYMENT_FAILED, 'webhook');
    }

    private function applyRefundEvent(Payment $payment, WebhookVerification $v): void
    {
        $amount = $v->amount;

        if ($amount === null) {
            return;
        }

        $refunded = min(
            (int) $payment->getRawOriginal('amount_minor'),
            (int) $payment->getRawOriginal('refunded_minor') + $amount->minor
        );

        $payment->forceFill([
            'refunded_minor' => $refunded,
            'status' => $refunded >= (int) $payment->getRawOriginal('amount_minor')
                ? Payment::STATUS_REFUNDED
                : Payment::STATUS_PARTIALLY_REFUNDED,
        ])->save();

        $this->states->syncRefundState($payment->order);
    }

    /**
     * Mark the order paid and hand it to the approval queue.
     *
     * Customer paid does NOT mean the supplier has been ordered from or
     * paid; those states are untouched here.
     */
    private function markOrderPaid(Payment $payment): void
    {
        $order = $payment->order;

        if ($order->isPaid()) {
            return;
        }

        $order->forceFill([
            'paid_at' => now(),
            'gateway_fee_actual_minor' => $payment->getRawOriginal('fee_minor'),
            // A demo/sandbox payment permanently marks the order as demo, so
            // it can never be fulfilled live or counted as revenue.
            'is_demo' => $order->is_demo || $payment->is_demo,
            'demo_reason' => $payment->is_demo ? "Settled by the {$payment->gateway_code} gateway in {$payment->mode} mode." : $order->demo_reason,
        ])->save();

        $this->states->transition($order, 'payment', Order::PAYMENT_PAID, 'system', null, 'Payment confirmed server side.');

        $autoApprove = (bool) settings('fulfilment.auto_approve', false);

        $this->states->transition(
            $order,
            'approval',
            $autoApprove ? Order::APPROVAL_APPROVED : Order::APPROVAL_AWAITING,
            'system',
            null,
            $autoApprove ? 'Automatic approval is enabled.' : 'Awaiting administrator approval before the supplier order is placed.',
        );
    }

    /**
     * @return string|null  A description of the mismatch, or null if it matches.
     */
    private function amountMismatch(Payment $payment, ?Money $actual): ?string
    {
        if ($actual === null) {
            return null;
        }

        if ($actual->currency !== $payment->currency) {
            return sprintf(
                'Provider reports %s but this payment is recorded in %s.',
                $actual->currency,
                $payment->currency
            );
        }

        $expected = (int) $payment->getRawOriginal('amount_minor');

        if ($actual->minor !== $expected) {
            return sprintf(
                'Provider reports %s but the order total is %s.',
                $actual->format(),
                Money::ofMinor($expected, $payment->currency)->format()
            );
        }

        return null;
    }
}
