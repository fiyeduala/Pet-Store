<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

use App\Support\Money\Money;

final readonly class SupplierVariant
{
    /**
     * @param  array<string, string>  $options   e.g. ['Size' => 'Medium']
     * @param  array<int, WarehouseStockReading>  $stock
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $supplierVariantId,
        public ?string $sku,
        public ?string $name,
        public ?Money $cost,
        public array $options = [],
        public ?int $weightGrams = null,
        public ?int $lengthMm = null,
        public ?int $widthMm = null,
        public ?int $heightMm = null,
        public array $stock = [],
        public array $images = [],
        public array $raw = [],
    ) {}

    public function optionSummary(): ?string
    {
        return $this->options === [] ? $this->name : implode(' / ', array_values($this->options));
    }
}
