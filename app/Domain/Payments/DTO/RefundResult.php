<?php

declare(strict_types=1);

namespace App\Domain\Payments\DTO;

use App\Support\Money\Money;

final readonly class RefundResult
{
    public function __construct(
        public bool $accepted,
        public string $status,
        public ?string $providerReference = null,
        public ?Money $amount = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
