<?php

declare(strict_types=1);

namespace App\Domain\Payments\DTO;

use App\Support\Money\Money;

final readonly class CaptureResult
{
    public function __construct(
        public bool $captured,
        public string $status,
        public ?string $providerReference = null,
        public ?Money $amount = null,
        public ?Money $fee = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
