<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Shared\MixedModeException;
use App\Domain\Shared\ModeGuard;
use App\Domain\Shipping\FulfilmentParcel;
use App\Domain\Shipping\WarehouseSelector;
use App\Domain\Supplier\DTO\SupplierOrderRequest;
use App\Domain\Supplier\Exceptions\SupplierOperationUnsupported;
use App\Domain\Supplier\Exceptions\SupplierRequestFailed;
use App\Domain\Supplier\Services\SupplierRegistry;
use App\Models\Fulfilment;
use App\Models\FulfilmentItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PackagingRecord;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Places orders with the supplier.
 *
 * The rules that matter most here:
 *
 *  - Nothing is submitted for an order that is not paid, approved,
 *    uncancelled and unrefunded. Re-checked at submission, not just at
 *    approval time.
 *  - Cost, stock and service are re-checked immediately before submission.
 *    A material change after the customer paid puts the order on hold
 *    rather than quietly overcharging or changing the delivery promise.
 *  - Every submission carries a stable idempotency key and our own
 *    reference. If the supplier times out, we RECONCILE by that reference.
 *    A missing response never triggers a second purchase.
 */
class FulfilmentService
{
    public function __construct(
        private readonly SupplierRegistry $suppliers,
        private readonly WarehouseSelector $warehouses,
        private readonly OrderStateMachine $states,
        private readonly ModeGuard $modeGuard,
        private readonly PreflightChecker $preflight,
    ) {}

    /**
     * Build the fulfilment rows for an order without contacting the supplier.
     *
     * @return array<int, Fulfilment>
     */
    public function plan(Order $order, Supplier $supplier): array
    {
        $lines = collect($order->items)
            ->filter(fn (OrderItem $i) => $i->variant !== null && $i->quantityOutstanding() > 0)
            ->map(fn (OrderItem $i) => ['variant' => $i->variant, 'quantity' => $i->quantityOutstanding(), 'order_item' => $i]);

        $plan = $this->warehouses->plan(
            $lines->map(fn (array $l) => ['variant' => $l['variant'], 'quantity' => $l['quantity']]),
            $order->market,
            allowOverseas: $order->fulfilment_mode === 'overseas_allowed',
        );

        $fulfilments = [];

        foreach ($plan->parcels as $index => $parcel) {
            $fulfilments[] = $this->createFulfilmentRow($order, $supplier, $parcel, $index, $lines->all());
        }

        if ($plan->unfulfillable !== []) {
            $this->states->raiseException(
                $order,
                'stock_changed',
                'Some items cannot be fulfilled from an eligible warehouse',
                $plan->reason,
                $order->fulfilment_mode === 'domestic_only'
                    ? 'Either wait for domestic restock, switch this order to overseas fulfilment (and tell the customer), or refund the affected lines.'
                    : 'Check supplier stock, then refund or substitute the affected lines.',
                ['unfulfillable' => array_map(fn (array $l) => $l['variant']->sku, $plan->unfulfillable)],
            );
        }

        return $fulfilments;
    }

    /**
     * @param  array<int, array{variant: mixed, quantity: int, order_item: OrderItem}>  $lineIndex
     */
    private function createFulfilmentRow(Order $order, Supplier $supplier, FulfilmentParcel $parcel, int $index, array $lineIndex): Fulfilment
    {
        // Stable for this order + warehouse + attempt scope, so a retry of
        // the same intent reuses the same row rather than creating another.
        $reference = sprintf('%s-P%d', $order->number, $index + 1);

        return DB::transaction(function () use ($order, $supplier, $parcel, $reference, $lineIndex) {
            $fulfilment = Fulfilment::firstOrCreate(
                ['internal_reference' => $reference],
                [
                    'order_id' => $order->id,
                    'supplier_id' => $supplier->id,
                    'warehouse_id' => $parcel->warehouse->id,
                    'idempotency_key' => hash('sha256', $reference.'|'.$order->id.'|'.$parcel->warehouse->id),
                    'state' => Fulfilment::STATE_PENDING,
                    'mode' => $supplier->mode,
                    'is_demo' => $order->is_demo,
                    'packaging_type' => $order->packaging_choice,
                    'packaging_record_id' => $order->packaging_record_id,
                    'currency' => $order->currency,
                ]
            );

            foreach ($parcel->lines as $line) {
                $orderItem = collect($lineIndex)->first(
                    fn (array $l) => $l['variant']->id === $line['variant']->id
                )['order_item'] ?? null;

                if ($orderItem === null) {
                    continue;
                }

                FulfilmentItem::firstOrCreate(
                    ['fulfilment_id' => $fulfilment->id, 'order_item_id' => $orderItem->id],
                    ['quantity' => $line['quantity']]
                );
            }

            return $fulfilment;
        });
    }

    /**
     * Submit a planned fulfilment to the supplier.
     */
    public function submit(Fulfilment $fulfilment, ?User $actor = null): SubmissionOutcome
    {
        $order = $fulfilment->order;
        $supplier = $fulfilment->supplier;

        // 1. Never fulfil an order that is not eligible, whatever the caller thinks.
        if (! $order->isFulfillable()) {
            return SubmissionOutcome::refused(
                'This order is not eligible for fulfilment (it must be paid, approved, not cancelled and not refunded).'
            );
        }

        // 2. Demo money can never cause a live supplier action, and vice versa.
        try {
            $this->modeGuard->assertFulfilmentAllowed($order, $supplier);
        } catch (MixedModeException $e) {
            $this->states->raiseException(
                $order,
                'mixed_mode_blocked',
                'Blocked a mixed-mode fulfilment',
                $e->getMessage(),
                'Align the order and the supplier integration mode before submitting.',
                ['fulfilment_id' => $fulfilment->id],
                severity: 'critical',
            );

            return SubmissionOutcome::refused($e->getMessage());
        }

        // 3. A fulfilment already sent must be reconciled, never resubmitted.
        if (in_array($fulfilment->state, [Fulfilment::STATE_SUBMITTED, Fulfilment::STATE_CONFIRMED], true)) {
            return SubmissionOutcome::alreadyDone($fulfilment);
        }

        if ($fulfilment->needsReconciliation()) {
            return $this->reconcile($fulfilment);
        }

        // 4. Re-check cost, stock and service right now. Anything that moved
        //    materially since the customer paid goes to a human.
        $preflight = $this->preflight->check($fulfilment);

        if (! $preflight->passed) {
            $this->states->hold($order, $preflight->summary(), $actor);

            $this->states->raiseException(
                $order,
                $preflight->exceptionType(),
                'Order held: conditions changed after payment',
                $preflight->summary(),
                'Review the change with the customer. Absorb it, re-quote, or refund — do not silently charge more.',
                ['fulfilment_id' => $fulfilment->id, 'findings' => $preflight->findings],
                severity: 'critical',
            );

            return SubmissionOutcome::held($preflight->summary());
        }

        $packagingId = $this->resolvePackaging($fulfilment);

        $fulfilment->forceFill([
            'state' => Fulfilment::STATE_SUBMITTING,
            'attempts' => $fulfilment->attempts + 1,
        ])->save();

        $this->states->transition($order, 'supplier_order', Order::SUPPLIER_SUBMITTING, $actor ? 'admin' : 'system', $actor);

        $adapter = $this->suppliers->for($supplier);

        $request = new SupplierOrderRequest(
            internalReference: $fulfilment->internal_reference,
            lines: $this->supplierLines($fulfilment),
            shippingAddress: $order->shipping_address,
            shippingServiceCode: $preflight->serviceCode,
            warehouseCode: $fulfilment->warehouse?->code,
            packagingId: $packagingId,
            note: 'Order '.$order->number,
        );

        try {
            $result = $adapter->createOrder($request);
        } catch (SupplierOperationUnsupported $e) {
            $fulfilment->forceFill(['state' => Fulfilment::STATE_PENDING, 'last_error' => $e->getMessage()])->save();
            $this->states->transition($order, 'supplier_order', Order::SUPPLIER_NOT_SUBMITTED, 'system');

            $this->states->raiseException(
                $order,
                'service_unavailable',
                'Supplier cannot perform this operation',
                $e->getMessage(),
                $e->manualWorkaround ?? 'Complete this step manually with the supplier and record the outcome here.',
                ['fulfilment_id' => $fulfilment->id],
            );

            return SubmissionOutcome::refused($e->getMessage());
        } catch (SupplierRequestFailed $e) {
            return $this->handleSubmissionFailure($fulfilment, $e);
        }

        return $this->recordSuccess($fulfilment, $result);
    }

    /**
     * A failure whose outcome is unknown is the dangerous one. We mark the
     * fulfilment for reconciliation and do NOT retry the create.
     */
    private function handleSubmissionFailure(Fulfilment $fulfilment, SupplierRequestFailed $e): SubmissionOutcome
    {
        $order = $fulfilment->order;

        if (! $e->outcomeUnknown) {
            $fulfilment->forceFill([
                'state' => Fulfilment::STATE_REJECTED,
                'last_error' => $e->getMessage(),
            ])->save();

            $this->states->transition($order, 'supplier_order', Order::SUPPLIER_REJECTED, 'system', null, $e->getMessage());

            $this->states->raiseException(
                $order,
                'supplier_rejected',
                'Supplier rejected the order',
                $e->getMessage(),
                'Fix the underlying problem (usually stock or address) and resubmit, or refund the customer.',
                ['fulfilment_id' => $fulfilment->id],
            );

            return SubmissionOutcome::rejected($e->getMessage());
        }

        Log::warning('Supplier submission outcome unknown; scheduling reconciliation.', [
            'fulfilment' => $fulfilment->internal_reference,
        ]);

        $fulfilment->forceFill([
            'state' => Fulfilment::STATE_NEEDS_RECONCILIATION,
            'last_error' => $e->getMessage(),
        ])->save();

        $this->states->transition($order, 'supplier_order', Order::SUPPLIER_RECONCILING, 'system', null, 'Supplier did not respond; outcome unknown.');

        $this->states->raiseException(
            $order,
            'supplier_timeout',
            'Supplier did not respond — outcome unknown',
            $e->getMessage(),
            'Do NOT resubmit. Reconcile by reference '.$fulfilment->internal_reference.' first; the order may already exist at the supplier.',
            ['fulfilment_id' => $fulfilment->id],
            severity: 'critical',
        );

        return SubmissionOutcome::needsReconciliation($e->getMessage());
    }

    /**
     * Ask the supplier what actually happened, using OUR reference.
     *
     * This is what makes a timeout safe: we find out whether the order
     * exists before deciding to create one.
     */
    public function reconcile(Fulfilment $fulfilment): SubmissionOutcome
    {
        $adapter = $this->suppliers->for($fulfilment->supplier);
        $existing = $adapter->findOrderByInternalReference($fulfilment->internal_reference);

        if ($existing !== null) {
            return $this->recordSuccess($fulfilment, $existing, reconciled: true);
        }

        // Confirmed absent: it is now safe to submit for the first time.
        $fulfilment->forceFill([
            'state' => Fulfilment::STATE_PENDING,
            'last_error' => 'Reconciled: no supplier order exists for this reference.',
        ])->save();

        $this->states->transition(
            $fulfilment->order,
            'supplier_order',
            Order::SUPPLIER_NOT_SUBMITTED,
            'system',
            null,
            'Reconciliation confirmed no supplier order exists; safe to submit.',
        );

        return SubmissionOutcome::reconciledAbsent();
    }

    private function recordSuccess(Fulfilment $fulfilment, \App\Domain\Supplier\DTO\SupplierOrderResult $result, bool $reconciled = false): SubmissionOutcome
    {
        $order = $fulfilment->order;

        DB::transaction(function () use ($fulfilment, $result, $order) {
            $fulfilment->forceFill([
                'state' => Fulfilment::STATE_CONFIRMED,
                'supplier_order_id' => $result->supplierOrderId,
                'supplier_order_number' => $result->supplierOrderNumber,
                'merchandise_cost_minor' => $this->minorIn($result->merchandiseCost, $fulfilment->currency),
                'shipping_cost_minor' => $this->minorIn($result->shippingCost, $fulfilment->currency),
                'response_payload' => $result->raw,
                'submitted_at' => $fulfilment->submitted_at ?? now(),
                'confirmed_at' => now(),
                'last_error' => null,
            ])->save();

            $order->forceFill([
                'submitted_at' => $order->submitted_at ?? now(),
                'merchandise_cost_minor' => (int) $order->fulfilments()->sum('merchandise_cost_minor'),
                'supplier_shipping_cost_minor' => (int) $order->fulfilments()->sum('shipping_cost_minor'),
            ])->save();
        });

        $this->states->transition(
            $order,
            'supplier_order',
            Order::SUPPLIER_CONFIRMED,
            'system',
            null,
            $reconciled ? 'Existing supplier order found during reconciliation.' : 'Supplier confirmed the order.',
        );

        return $reconciled
            ? SubmissionOutcome::reconciledFound($fulfilment)
            : SubmissionOutcome::submitted($fulfilment);
    }

    /**
     * Decide which packaging actually ships, honouring the shortage policy.
     */
    private function resolvePackaging(Fulfilment $fulfilment): ?string
    {
        if ($fulfilment->packaging_type === PackagingRecord::TYPE_STANDARD) {
            return null;
        }

        $record = $fulfilment->packagingRecord;
        $quantity = (int) $fulfilment->items()->sum('quantity');

        if ($record?->isUsableFor($fulfilment->warehouse, $quantity)) {
            return $record->api_selectable ? $record->supplier_packaging_id : null;
        }

        $policy = (string) settings('packaging.shortage_policy', 'fallback_standard');

        if ($policy === 'hold_order') {
            $this->states->hold(
                $fulfilment->order,
                'Branded packaging is unavailable and the shortage policy is set to hold the order.'
            );
        }

        // Record what actually happened so the choice is auditable later.
        $fulfilment->forceFill([
            'packaging_type' => PackagingRecord::TYPE_STANDARD,
            'last_error' => 'Branded packaging unavailable; '.($record?->blockingReason() ?? 'no record matched').'.',
        ])->save();

        return null;
    }

    /**
     * @return array<int, array{supplier_variant_id: string, quantity: int}>
     */
    private function supplierLines(Fulfilment $fulfilment): array
    {
        return $fulfilment->items()
            ->with('orderItem')
            ->get()
            ->map(fn (FulfilmentItem $item) => [
                'supplier_variant_id' => (string) $item->orderItem->supplier_variant_id,
                'quantity' => $item->quantity,
            ])
            ->values()
            ->all();
    }

    private function minorIn(?Money $money, string $currency): ?int
    {
        if ($money === null) {
            return null;
        }

        return $money->currency === $currency ? $money->minor : null;
    }
}
