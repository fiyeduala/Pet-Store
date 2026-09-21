<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Shared\MixedModeException;
use App\Domain\Shared\ModeGuard;
use App\Domain\Supplier\Exceptions\SupplierRequestFailed;
use App\Domain\Supplier\Services\SupplierRegistry;
use App\Models\Fulfilment;
use App\Models\Order;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Pays the supplier for a confirmed order.
 *
 * This is a DIFFERENT financial process from collecting money from the
 * customer. Funds do not flow from the customer's PayPal or Paystack
 * payment into the CJ account; the store is paid, and the store separately
 * funds its supplier balance.
 *
 * Because it spends real money it requires its own authorisation, has its
 * own toggle (off by default), and is protected by a unique idempotency
 * key so a retry cannot pay twice.
 */
class SupplierPaymentService
{
    public function __construct(
        private readonly SupplierRegistry $suppliers,
        private readonly OrderStateMachine $states,
        private readonly ModeGuard $modeGuard,
    ) {}

    /**
     * Whether this fulfilment may be paid right now, and why not if not.
     */
    public function blockingReason(Fulfilment $fulfilment): ?string
    {
        $order = $fulfilment->order;

        if ($fulfilment->state !== Fulfilment::STATE_CONFIRMED) {
            return 'The supplier order is not confirmed yet.';
        }

        if (! $order->isFulfillable()) {
            return 'The order is not paid, approved and active.';
        }

        if ($fulfilment->supplierPayment?->isSettled()) {
            return 'This fulfilment has already been paid.';
        }

        try {
            $this->modeGuard->assertSupplierPaymentAllowed($order, $fulfilment->supplier);
        } catch (MixedModeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Authorise and attempt the supplier charge.
     *
     * @param  bool  $chargeAuthorised  must be explicitly true; the caller is
     *                                  responsible for making clear to the
     *                                  admin that this spends money.
     */
    public function pay(Fulfilment $fulfilment, User $actor, bool $chargeAuthorised): SupplierPayment
    {
        if (! $chargeAuthorised) {
            throw new \LogicException('A supplier charge must be explicitly authorised by the caller.');
        }

        $blocking = $this->blockingReason($fulfilment);

        if ($blocking !== null) {
            throw new \RuntimeException($blocking);
        }

        $order = $fulfilment->order;
        $amount = Money::ofMinor($fulfilment->totalCostMinor(), $fulfilment->currency);

        // The unique index on idempotency_key is the real guarantee here:
        // a concurrent second attempt cannot create a second payment row.
        $payment = DB::transaction(function () use ($fulfilment, $actor, $amount) {
            return SupplierPayment::firstOrCreate(
                ['idempotency_key' => 'sp-'.$fulfilment->internal_reference],
                [
                    'fulfilment_id' => $fulfilment->id,
                    'supplier_id' => $fulfilment->supplier_id,
                    'amount_minor' => $amount->minor,
                    'currency' => $amount->currency,
                    'status' => SupplierPayment::STATUS_PENDING,
                    'mode' => $fulfilment->mode,
                    'is_demo' => $fulfilment->is_demo,
                    'authorised_by' => $actor->id,
                    'authorised_at' => now(),
                ]
            );
        });

        if ($payment->isSettled()) {
            return $payment;
        }

        $payment->forceFill(['status' => SupplierPayment::STATUS_AUTHORISED])->save();
        $this->states->transition($order, 'supplier_payment', Order::SUPPLIER_PAY_AUTHORISING, 'admin', $actor);

        $adapter = $this->suppliers->for($fulfilment->supplier);

        // Check the balance first so an obviously-doomed charge becomes a
        // clear exception rather than a provider error.
        $balance = $adapter->getBalance();

        if ($balance !== null && $balance->currency === $amount->currency && $balance->lessThan($amount)) {
            return $this->recordInsufficientBalance($payment, $order, $balance, $amount);
        }

        try {
            $result = $adapter->payOrder((string) $fulfilment->supplier_order_id, $amount);
        } catch (SupplierRequestFailed $e) {
            // An unknown outcome must not be retried blindly.
            $payment->forceFill([
                'status' => SupplierPayment::STATUS_FAILED,
                'last_error' => $e->getMessage(),
            ])->save();

            $this->states->transition($order, 'supplier_payment', Order::SUPPLIER_PAY_FAILED, 'system', null, $e->getMessage());

            $this->states->raiseException(
                $order,
                $e->outcomeUnknown ? 'supplier_timeout' : 'supplier_rejected',
                'Supplier payment failed',
                $e->getMessage(),
                $e->outcomeUnknown
                    ? 'Check the supplier dashboard before retrying: the charge may already have gone through.'
                    : 'Resolve the failure with the supplier, then retry from this order.',
                ['fulfilment_id' => $fulfilment->id],
                severity: 'critical',
            );

            return $payment->refresh();
        }

        if (! $result->paid) {
            $status = str_contains(strtolower($result->status), 'balance')
                ? SupplierPayment::STATUS_INSUFFICIENT
                : SupplierPayment::STATUS_FAILED;

            $payment->forceFill([
                'status' => $status,
                'last_error' => $result->failureReason,
                'raw_payload' => $result->raw,
            ])->save();

            $this->states->transition(
                $order,
                'supplier_payment',
                $status === SupplierPayment::STATUS_INSUFFICIENT ? Order::SUPPLIER_PAY_INSUFFICIENT : Order::SUPPLIER_PAY_FAILED,
                'system',
                null,
                $result->failureReason,
            );

            $this->states->raiseException(
                $order,
                $status === SupplierPayment::STATUS_INSUFFICIENT ? 'insufficient_balance' : 'supplier_rejected',
                'Supplier payment was not completed',
                $result->failureReason,
                $status === SupplierPayment::STATUS_INSUFFICIENT
                    ? 'Top up the supplier balance, then retry the payment from this order.'
                    : 'Investigate with the supplier, then retry.',
                ['fulfilment_id' => $fulfilment->id],
            );

            return $payment->refresh();
        }

        $payment->forceFill([
            'status' => SupplierPayment::STATUS_PAID,
            'provider_reference' => $result->reference,
            'paid_at' => now(),
            'raw_payload' => $result->raw,
            'last_error' => null,
        ])->save();

        $this->states->transition($order, 'supplier_payment', Order::SUPPLIER_PAY_PAID, 'admin', $actor, 'Supplier paid.');

        return $payment->refresh();
    }

    private function recordInsufficientBalance(SupplierPayment $payment, Order $order, Money $balance, Money $amount): SupplierPayment
    {
        $message = sprintf(
            'Supplier balance is %s but this order needs %s.',
            $balance->format(),
            $amount->format()
        );

        $payment->forceFill([
            'status' => SupplierPayment::STATUS_INSUFFICIENT,
            'last_error' => $message,
        ])->save();

        $this->states->transition($order, 'supplier_payment', Order::SUPPLIER_PAY_INSUFFICIENT, 'system', null, $message);

        $this->states->raiseException(
            $order,
            'insufficient_balance',
            'Supplier balance too low',
            $message,
            'Top up the supplier account, then retry the payment from this order.',
            ['fulfilment_id' => $payment->fulfilment_id],
        );

        return $payment->refresh();
    }
}
