<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\OperationalException;
use App\Models\OrderStateEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Guards and records transitions across the order's five state machines.
 *
 * Each machine moves independently. Knowing that the customer paid tells
 * you nothing about whether the supplier has been ordered from, and this
 * class will not let one imply the other.
 */
class OrderStateMachine
{
    /**
     * Allowed transitions per machine. Anything not listed is refused.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    public const TRANSITIONS = [
        'payment' => [
            Order::PAYMENT_PENDING => [Order::PAYMENT_AUTHORISED, Order::PAYMENT_PAID, Order::PAYMENT_FAILED, Order::PAYMENT_CANCELLED],
            Order::PAYMENT_AUTHORISED => [Order::PAYMENT_PAID, Order::PAYMENT_FAILED, Order::PAYMENT_CANCELLED],
            Order::PAYMENT_PAID => [Order::PAYMENT_PARTIALLY_REFUNDED, Order::PAYMENT_REFUNDED],
            Order::PAYMENT_PARTIALLY_REFUNDED => [Order::PAYMENT_REFUNDED, Order::PAYMENT_PARTIALLY_REFUNDED],
            Order::PAYMENT_FAILED => [Order::PAYMENT_PENDING, Order::PAYMENT_CANCELLED],
            Order::PAYMENT_REFUNDED => [],
            Order::PAYMENT_CANCELLED => [],
        ],
        'approval' => [
            Order::APPROVAL_NOT_REQUIRED => [Order::APPROVAL_AWAITING, Order::APPROVAL_APPROVED, Order::APPROVAL_ON_HOLD],
            Order::APPROVAL_AWAITING => [Order::APPROVAL_APPROVED, Order::APPROVAL_REJECTED, Order::APPROVAL_ON_HOLD],
            Order::APPROVAL_APPROVED => [Order::APPROVAL_ON_HOLD, Order::APPROVAL_REJECTED],
            Order::APPROVAL_ON_HOLD => [Order::APPROVAL_APPROVED, Order::APPROVAL_REJECTED, Order::APPROVAL_AWAITING],
            Order::APPROVAL_REJECTED => [Order::APPROVAL_AWAITING],
        ],
        'supplier_order' => [
            Order::SUPPLIER_NOT_SUBMITTED => [Order::SUPPLIER_SUBMITTING, Order::SUPPLIER_CANCELLED],
            Order::SUPPLIER_SUBMITTING => [Order::SUPPLIER_SUBMITTED, Order::SUPPLIER_CONFIRMED, Order::SUPPLIER_REJECTED, Order::SUPPLIER_RECONCILING],
            Order::SUPPLIER_SUBMITTED => [Order::SUPPLIER_CONFIRMED, Order::SUPPLIER_REJECTED, Order::SUPPLIER_CANCELLED, Order::SUPPLIER_RECONCILING],
            // A reconciling order can resolve either way once we know what
            // actually happened at the supplier.
            Order::SUPPLIER_RECONCILING => [Order::SUPPLIER_SUBMITTED, Order::SUPPLIER_CONFIRMED, Order::SUPPLIER_REJECTED, Order::SUPPLIER_NOT_SUBMITTED, Order::SUPPLIER_CANCELLED],
            Order::SUPPLIER_CONFIRMED => [Order::SUPPLIER_CANCELLED],
            Order::SUPPLIER_REJECTED => [Order::SUPPLIER_NOT_SUBMITTED, Order::SUPPLIER_SUBMITTING],
            Order::SUPPLIER_CANCELLED => [],
        ],
        'supplier_payment' => [
            Order::SUPPLIER_PAY_NOT_PAID => [Order::SUPPLIER_PAY_AUTHORISING],
            Order::SUPPLIER_PAY_AUTHORISING => [Order::SUPPLIER_PAY_PAID, Order::SUPPLIER_PAY_FAILED, Order::SUPPLIER_PAY_INSUFFICIENT],
            Order::SUPPLIER_PAY_FAILED => [Order::SUPPLIER_PAY_AUTHORISING],
            Order::SUPPLIER_PAY_INSUFFICIENT => [Order::SUPPLIER_PAY_AUTHORISING],
            Order::SUPPLIER_PAY_PAID => [],
        ],
        'shipment' => [
            Order::SHIPMENT_NONE => [Order::SHIPMENT_PARTIAL, Order::SHIPMENT_SHIPPED],
            Order::SHIPMENT_PARTIAL => [Order::SHIPMENT_SHIPPED, Order::SHIPMENT_PARTIALLY_DELIVERED, Order::SHIPMENT_PARTIAL, Order::SHIPMENT_RETURNED],
            Order::SHIPMENT_SHIPPED => [Order::SHIPMENT_PARTIALLY_DELIVERED, Order::SHIPMENT_DELIVERED, Order::SHIPMENT_RETURNED],
            Order::SHIPMENT_PARTIALLY_DELIVERED => [Order::SHIPMENT_DELIVERED, Order::SHIPMENT_RETURNED, Order::SHIPMENT_PARTIALLY_DELIVERED],
            Order::SHIPMENT_DELIVERED => [Order::SHIPMENT_RETURNED],
            Order::SHIPMENT_RETURNED => [],
        ],
    ];

    private const COLUMNS = [
        'payment' => 'payment_state',
        'approval' => 'approval_state',
        'supplier_order' => 'supplier_order_state',
        'supplier_payment' => 'supplier_payment_state',
        'shipment' => 'shipment_state',
    ];

    /**
     * Move one machine to a new state, recording an audit event.
     *
     * Returns false when the transition is not allowed, so callers can
     * branch without catching exceptions for an expected no-op.
     */
    public function transition(
        Order $order,
        string $machine,
        string $to,
        string $actorType = 'system',
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = [],
    ): bool {
        $column = self::COLUMNS[$machine]
            ?? throw new InvalidArgumentException("Unknown order state machine [{$machine}].");

        $from = (string) $order->{$column};

        if ($from === $to) {
            return true;
        }

        if (! $this->canTransition($machine, $from, $to)) {
            return false;
        }

        DB::transaction(function () use ($order, $column, $machine, $from, $to, $actorType, $actor, $reason, $metadata) {
            $order->forceFill([$column => $to])->save();

            OrderStateEvent::create([
                'order_id' => $order->id,
                'machine' => $machine,
                'from_state' => $from,
                'to_state' => $to,
                'actor_type' => $actorType,
                'actor_id' => $actor?->id,
                'reason' => $reason,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            $this->refreshLifecycle($order);
        });

        return true;
    }

    public function canTransition(string $machine, string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$machine][$from] ?? [], true);
    }

    /**
     * @return array<int, string>
     */
    public function allowedFrom(string $machine, string $from): array
    {
        return self::TRANSITIONS[$machine][$from] ?? [];
    }

    /**
     * Derive the single headline status shown in lists and to customers.
     * It is a projection of the five machines, never a substitute for them.
     */
    public function refreshLifecycle(Order $order): string
    {
        $status = match (true) {
            $order->cancelled_at !== null => 'cancelled',
            $order->payment_state === Order::PAYMENT_REFUNDED => 'refunded',
            $order->shipment_state === Order::SHIPMENT_DELIVERED => 'delivered',
            $order->shipment_state === Order::SHIPMENT_PARTIALLY_DELIVERED => 'partially_delivered',
            $order->shipment_state === Order::SHIPMENT_SHIPPED => 'shipped',
            $order->shipment_state === Order::SHIPMENT_PARTIAL => 'partially_shipped',
            $order->approval_state === Order::APPROVAL_ON_HOLD => 'on_hold',
            $order->supplier_order_state === Order::SUPPLIER_RECONCILING => 'reconciling',
            $order->supplier_order_state === Order::SUPPLIER_CONFIRMED => 'processing',
            $order->supplier_order_state === Order::SUPPLIER_SUBMITTED => 'submitted_to_supplier',
            $order->approval_state === Order::APPROVAL_AWAITING => 'awaiting_approval',
            $order->approval_state === Order::APPROVAL_APPROVED => 'approved',
            $order->payment_state === Order::PAYMENT_PAID => 'paid',
            $order->payment_state === Order::PAYMENT_FAILED => 'payment_failed',
            default => 'new',
        };

        if ($order->lifecycle_status !== $status) {
            $order->forceFill(['lifecycle_status' => $status])->saveQuietly();
        }

        return $status;
    }

    /**
     * Recompute payment state from the amount actually refunded.
     */
    public function syncRefundState(Order $order): void
    {
        $refunded = (int) $order->refunds()
            ->where('status', \App\Models\Refund::STATUS_COMPLETED)
            ->sum('amount_minor');

        $total = (int) $order->getRawOriginal('total_minor');
        $order->forceFill(['refunded_minor' => min($refunded, $total)])->save();

        if ($refunded <= 0) {
            return;
        }

        $this->transition(
            $order,
            'payment',
            $refunded >= $total ? Order::PAYMENT_REFUNDED : Order::PAYMENT_PARTIALLY_REFUNDED,
            'system',
            null,
            'Refund reconciliation.',
        );
    }

    /**
     * Put something in front of a human instead of guessing.
     */
    public function raiseException(
        ?Order $order,
        string $type,
        string $title,
        ?string $detail = null,
        ?string $suggestedAction = null,
        array $metadata = [],
        string $severity = 'warning',
    ): OperationalException {
        return OperationalException::create([
            'type' => $type,
            'severity' => $severity,
            'state' => OperationalException::STATE_OPEN,
            'title' => $title,
            'detail' => $detail,
            'suggested_action' => $suggestedAction,
            'order_id' => $order?->id,
            'fulfilment_id' => $metadata['fulfilment_id'] ?? null,
            'payment_id' => $metadata['payment_id'] ?? null,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Hold an order for human resolution, e.g. when cost or stock changed
     * materially after the customer already paid.
     */
    public function hold(Order $order, string $reason, ?User $actor = null): void
    {
        $order->forceFill(['hold_reason' => $reason])->save();
        $this->transition($order, 'approval', Order::APPROVAL_ON_HOLD, $actor ? 'admin' : 'system', $actor, $reason);
    }
}
