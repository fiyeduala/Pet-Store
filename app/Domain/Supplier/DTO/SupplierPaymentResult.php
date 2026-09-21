<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

use App\Support\Money\Money;

final readonly class SupplierPaymentResult
{
    public function __construct(
        public bool $paid,
        public string $status,
        public ?Money $amount = null,
        public ?string $reference = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
