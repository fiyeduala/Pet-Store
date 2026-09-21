<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

/**
 * One warehouse's view of one variant.
 *
 * `quantity` is null when the supplier did not report a number. That is not
 * the same as zero, and callers must not collapse it into one.
 */
final readonly class WarehouseStockReading
{
    public function __construct(
        public string $warehouseCode,
        public string $countryCode,
        public ?int $quantity,
        public ?string $warehouseName = null,
        public array $raw = [],
    ) {}

    public function isKnown(): bool
    {
        return $this->quantity !== null;
    }
}
