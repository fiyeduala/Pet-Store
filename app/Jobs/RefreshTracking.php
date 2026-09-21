<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Supplier\Services\SupplierRegistry;
use App\Models\Heartbeat;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Polls carrier tracking for shipments that are still moving.
 *
 * Delivery is only recorded when the feed actually evidences it; we never
 * mark something delivered because enough time has passed.
 */
class RefreshTracking implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('refresh-tracking'))->expireAfter(1200)];
    }

    public function handle(SupplierRegistry $suppliers, OrderStateMachine $states): void
    {
        $shipments = Shipment::query()
            ->whereNotNull('tracking_number')
            ->whereNotIn('state', ['delivered', 'returned'])
            ->where(fn ($q) => $q->whereNull('tracking_checked_at')->orWhere('tracking_checked_at', '<', now()->subHours(6)))
            ->limit(100)
            ->get();

        $updated = 0;

        foreach ($shipments as $shipment) {
            $supplier = $shipment->fulfilment?->supplier;

            if ($supplier === null) {
                continue;
            }

            $result = $suppliers->for($supplier)->trackShipment((string) $shipment->tracking_number);

            $shipment->forceFill(['tracking_checked_at' => now()])->save();

            if ($result === null) {
                continue;
            }

            $shipment->forceFill([
                'state' => $result->state,
                'carrier' => $shipment->carrier ?: $result->carrier,
                'delivered_at' => $result->deliveredAt,
                'delivery_evidence' => $result->deliveryEvidence,
                'raw_payload' => $result->raw,
            ])->save();

            $this->syncOrderShipmentState($shipment->order, $states);
            $updated++;
        }

        Heartbeat::beat('tracking_refresh', 'ok', "{$updated} shipment(s) updated.");
    }

    private function syncOrderShipmentState(Order $order, OrderStateMachine $states): void
    {
        $shipments = $order->shipments()->get();

        if ($shipments->isEmpty()) {
            return;
        }

        $delivered = $shipments->filter(fn (Shipment $s) => $s->isEvidencedDelivered())->count();

        $to = match (true) {
            $delivered === $shipments->count() => Order::SHIPMENT_DELIVERED,
            $delivered > 0 => Order::SHIPMENT_PARTIALLY_DELIVERED,
            default => null,
        };

        if ($to === null) {
            return;
        }

        $states->transition($order, 'shipment', $to, 'system', null, 'Carrier tracking reported delivery.');

        if ($to === Order::SHIPMENT_DELIVERED) {
            $order->forceFill(['delivered_at' => now()])->save();
        }
    }
}
