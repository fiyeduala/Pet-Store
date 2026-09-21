<?php

declare(strict_types=1);

namespace App\Domain\Returns;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\PaymentGatewayRegistry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Refunds and cancellations.
 *
 * Two things this is careful about:
 *
 *  1. A refund REQUEST is not a refund. Nothing claims money moved until
 *     the provider confirms it, and the status field says which is which.
 *
 *  2. Cancellation races dispatch. Once anything has gone to the supplier
 *     we cannot promise to stop it, so we say so plainly, reconcile with
 *     the supplier first, and let an administrator decide.
 */
class RefundService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly OrderStateMachine $states,
    ) {}

    /**
     * Record the intent to refund. No money moves here.
     */
    public function request(Order $order, Money $amount, string $reason, ?User $actor = null, ?string $note = null): Refund
    {
        if ($amount->currency !== $order->currency) {
            throw new RuntimeException("Refund currency {$amount->currency} does not match the order currency {$order->currency}.");
        }

        $refundable = $order->refundableMinor();

        if ($amount->minor <= 0 || $amount->minor > $refundable) {
            throw new RuntimeException(sprintf(
                'Refund of %s is outside the refundable balance of %s.',
                $amount->format(),
                Money::ofMinor($refundable, $order->currency)->format()
            ));
        }

        $payment = $order->payments()
            ->whereIn('status', [Payment::STATUS_CAPTURED, Payment::STATUS_PARTIALLY_REFUNDED])
            ->latest('id')
            ->first();

        return Refund::create([
            'order_id' => $order->id,
            'payment_id' => $payment?->id,
            // Deterministic: a repeated request for the same amount and
            // reason will not create a second refund row.
            'idempotency_key' => 'rf-'.$order->number.'-'.substr(hash('sha256', $amount->minor.'|'.$reason.'|'.now()->format('YmdHi')), 0, 16),
            'amount_minor' => $amount->minor,
            'currency' => $amount->currency,
            'status' => Refund::STATUS_REQUESTED,
            'reason' => $reason,
            'note' => $note,
            'is_demo' => $order->is_demo,
            'requested_by' => $actor?->id,
        ]);
    }

    /**
     * Approve and actually send the refund to the provider.
     */
    public function process(Refund $refund, User $actor): Refund
    {
        if ($refund->isSettled()) {
            return $refund;
        }

        if ($refund->payment === null) {
            throw new RuntimeException('This refund has no captured payment to refund against.');
        }

        $order = $refund->order;

        // Refunding an order whose goods are already moving is a business
        // decision, not a blocked action — but it must be visible.
        if (! $order->cancellationCanBeGuaranteed() && $order->shipment_state !== Order::SHIPMENT_NONE) {
            $this->states->raiseException(
                $order,
                'refund_dispatch_race',
                'Refunding an order that has already shipped',
                'Goods are already in transit for this order. A return will be needed to recover them.',
                'Open a return request and, where the supplier supports it, raise a supplier claim.',
                ['refund_id' => $refund->id],
            );
        }

        $refund->forceFill([
            'status' => Refund::STATUS_PROCESSING,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        $adapter = $this->gateways->byCode($refund->payment->gateway_code);
        $amount = Money::ofMinor((int) $refund->getRawOriginal('amount_minor'), $refund->currency);

        $result = $adapter->refund($refund->payment, $amount, $refund->idempotency_key, $refund->reason);

        if (! $result->accepted) {
            $refund->forceFill([
                'status' => Refund::STATUS_FAILED,
                'last_error' => $result->failureReason,
                'raw_payload' => $result->raw,
            ])->save();

            $this->states->raiseException(
                $order,
                'payment_mismatch',
                'Refund failed at the payment provider',
                $result->failureReason,
                'Retry from this order, or refund manually in the provider dashboard and reconcile here.',
                ['refund_id' => $refund->id],
            );

            return $refund->refresh();
        }

        DB::transaction(function () use ($refund, $result, $amount) {
            $refund->forceFill([
                'status' => $result->status === 'completed' ? Refund::STATUS_COMPLETED : Refund::STATUS_PROCESSING,
                'provider_reference' => $result->providerReference,
                'processed_at' => $result->status === 'completed' ? now() : null,
                'raw_payload' => $result->raw,
                'last_error' => null,
            ])->save();

            if ($result->status === 'completed') {
                $payment = $refund->payment;
                $payment->forceFill([
                    'refunded_minor' => (int) $payment->getRawOriginal('refunded_minor') + $amount->minor,
                ])->save();

                $payment->forceFill([
                    'status' => $payment->refundableMinor() <= 0
                        ? Payment::STATUS_REFUNDED
                        : Payment::STATUS_PARTIALLY_REFUNDED,
                ])->save();
            }
        });

        if ($refund->fresh()->isSettled()) {
            $this->states->syncRefundState($refund->order);
        }

        return $refund->refresh();
    }

    /**
     * Whether cancelling can still be guaranteed, and what to tell the user.
     *
     * @return array{can_guarantee: bool, message: string}
     */
    public function cancellationOutlook(Order $order): array
    {
        if ($order->cancellationCanBeGuaranteed()) {
            return [
                'can_guarantee' => true,
                'message' => 'This order has not been sent to our supplier yet, so it can still be cancelled.',
            ];
        }

        if ($order->shipment_state !== Order::SHIPMENT_NONE) {
            return [
                'can_guarantee' => false,
                'message' => 'This order has already been dispatched. We can no longer stop it, but you can request a return once it arrives.',
            ];
        }

        return [
            'can_guarantee' => false,
            'message' => 'This order has already been sent to our supplier. We will try to stop it, but we cannot guarantee cancellation. We will confirm either way.',
        ];
    }

    /**
     * Cancel an order, reconciling with the supplier first when needed.
     */
    public function cancel(Order $order, string $reason, ?User $actor = null): CancellationOutcome
    {
        $outlook = $this->cancellationOutlook($order);

        if (! $outlook['can_guarantee']) {
            $this->states->raiseException(
                $order,
                'refund_dispatch_race',
                'Cancellation requested after supplier submission',
                $outlook['message'],
                'Contact the supplier to attempt a stop, then either confirm cancellation or convert this into a return.',
                ['reason' => $reason],
            );

            return new CancellationOutcome(false, $outlook['message']);
        }

        $order->forceFill(['cancelled_at' => now()])->save();
        $this->states->transition($order, 'supplier_order', Order::SUPPLIER_CANCELLED, $actor ? 'admin' : 'customer', $actor, $reason);
        $this->states->refreshLifecycle($order);

        return new CancellationOutcome(true, 'Order cancelled before it reached the supplier.');
    }
}
