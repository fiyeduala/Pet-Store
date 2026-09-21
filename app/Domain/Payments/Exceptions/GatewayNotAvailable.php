<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

/**
 * Thrown when a gateway cannot legitimately take this payment: disabled,
 * unverified, or unable to accept the order's currency.
 *
 * Checkout must fail clearly on this. It must never silently substitute a
 * different currency or a demo payment.
 */
class GatewayNotAvailable extends PaymentException {}
