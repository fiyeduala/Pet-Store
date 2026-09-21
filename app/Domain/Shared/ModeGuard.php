<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Supplier;

/**
 * Keeps demo money and real money apart.
 *
 * The rule this enforces: a payment taken in demo or sandbox mode can never
 * cause a live supplier action, and a live payment can never be fulfilled
 * through the demo adapter. Mixing the two is how a test order turns into a
 * real purchase, so it is refused loudly rather than handled gracefully.
 */
class ModeGuard
{
    public const REAL_MODES = [PaymentGateway::MODE_LIVE];

    /**
     * @throws MixedModeException
     */
    public function assertFulfilmentAllowed(Order $order, Supplier $supplier): void
    {
        $reason = $this->fulfilmentBlockReason($order, $supplier);

        if ($reason !== null) {
            throw new MixedModeException($reason);
        }
    }

    /**
     * Returns why this order may not be fulfilled through this supplier, or
     * null when the modes are consistent.
     */
    public function fulfilmentBlockReason(Order $order, Supplier $supplier): ?string
    {
        $supplierIsLive = $supplier->mode === Supplier::MODE_LIVE;

        if ($order->is_demo && $supplierIsLive) {
            return 'This is a demo order. It cannot be fulfilled through the live supplier integration.';
        }

        if (! $order->is_demo && ! $supplierIsLive) {
            return sprintf(
                'This is a real order but the supplier integration is in %s mode. Switch the supplier to live, or the order will not actually be placed.',
                $supplier->mode
            );
        }

        return null;
    }

    /**
     * An order is a demo order when the payment that settled it was not a
     * live payment. This is decided once, at payment time, and stored.
     */
    public function orderIsDemoFor(PaymentGateway $gateway): bool
    {
        return ! in_array($gateway->mode, self::REAL_MODES, true);
    }

    public function assertSupplierPaymentAllowed(Order $order, Supplier $supplier): void
    {
        $this->assertFulfilmentAllowed($order, $supplier);
    }
}
