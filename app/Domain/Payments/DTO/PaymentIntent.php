<?php

declare(strict_types=1);

namespace App\Domain\Payments\DTO;

use App\Support\Money\Money;

/**
 * A payment the gateway has been asked to collect but has not yet settled.
 */
final readonly class PaymentIntent
{
    public function __construct(
        public string $providerOrderId,
        public Money $amount,
        public ?string $approvalUrl = null,
        /** Only ever a documented PUBLIC identifier. Never a secret. */
        public ?string $clientToken = null,
        public array $raw = [],
    ) {}
}
