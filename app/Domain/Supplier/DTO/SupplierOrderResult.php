<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

use App\Support\Money\Money;

final readonly class SupplierOrderResult
{
    public function __construct(
        public string $supplierOrderId,
        public ?string $supplierOrderNumber,
        public string $status,
        public ?Money $merchandiseCost = null,
        public ?Money $shippingCost = null,
        public ?string $internalReference = null,
        public array $raw = [],
    ) {}
}
