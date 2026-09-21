<?php

declare(strict_types=1);

namespace App\Domain\Returns;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReturnService
{
    public function __construct(private readonly RefundService $refunds) {}

    /**
     * @param  array<int, array{order_item_id: int, quantity: int, condition?: string}>  $lines
     */
    public function open(Order $order, array $lines, string $reason, ?string $note = null): ReturnRequest
    {
        if (! $order->isPaid()) {
            throw new RuntimeException('Only a paid order can be returned.');
        }

        return DB::transaction(function () use ($order, $lines, $reason, $note) {
            $request = ReturnRequest::create([
                'number' => 'RET-'.strtoupper(bin2hex(random_bytes(4))),
                'order_id' => $order->id,
                'state' => ReturnRequest::STATE_REQUESTED,
                'reason' => $reason,
                'customer_note' => $note,
                'requested_at' => now(),
            ]);

            foreach ($lines as $line) {
                $item = $order->items()->findOrFail($line['order_item_id']);
                $quantity = min((int) $line['quantity'], $item->quantity - $item->quantity_returned);

                if ($quantity <= 0) {
                    continue;
                }

                ReturnRequestItem::create([
                    'return_request_id' => $request->id,
                    'order_item_id' => $item->id,
                    'quantity' => $quantity,
                    'condition' => $line['condition'] ?? null,
                ]);
            }

            return $request->load('items');
        });
    }

    public function approve(ReturnRequest $request, User $actor, ?string $adminNote = null): ReturnRequest
    {
        $request->forceFill([
            'state' => ReturnRequest::STATE_AWAITING_RETURN,
            'approved_at' => now(),
            'admin_note' => $adminNote,
        ])->save();

        return $request;
    }

    public function markReceived(ReturnRequest $request, User $actor): ReturnRequest
    {
        DB::transaction(function () use ($request) {
            $request->forceFill([
                'state' => ReturnRequest::STATE_RECEIVED,
                'received_at' => now(),
            ])->save();

            foreach ($request->items as $line) {
                $item = $line->orderItem;
                $item->forceFill([
                    'quantity_returned' => min($item->quantity, $item->quantity_returned + $line->quantity),
                ])->save();
            }
        });

        return $request->refresh();
    }

    /**
     * Refund the value of the returned lines. Creates a refund REQUEST; it
     * is not money moved until processed and confirmed.
     */
    public function refundReturnedLines(ReturnRequest $request, User $actor): ReturnRequest
    {
        $order = $request->order;
        $minor = 0;

        foreach ($request->items as $line) {
            $item = $line->orderItem;
            // Refund the per-unit value actually charged, tax included.
            $perUnit = intdiv($item->lineTotalMinor(), max(1, $item->quantity));
            $minor += $perUnit * $line->quantity;
        }

        $minor = min($minor, $order->refundableMinor());

        if ($minor <= 0) {
            throw new RuntimeException('There is nothing left to refund on this order.');
        }

        $refund = $this->refunds->request(
            $order,
            Money::ofMinor($minor, $order->currency),
            'Return '.$request->number,
            $actor,
        );

        $request->forceFill(['refund_id' => $refund->id])->save();

        return $request->refresh();
    }

    public function close(ReturnRequest $request, ?string $supplierClaimReference = null, ?string $claimNotes = null): ReturnRequest
    {
        $request->forceFill([
            'state' => ReturnRequest::STATE_CLOSED,
            'closed_at' => now(),
            'supplier_claim_reference' => $supplierClaimReference,
            'supplier_claim_notes' => $claimNotes,
        ])->save();

        return $request;
    }
}
