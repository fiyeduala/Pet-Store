<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

final readonly class ShippingQuoteRequest
{
    /**
     * @param  array<int, array{supplier_variant_id: string, quantity: int, weight_grams?: int}>  $lines
     */
    public function __construct(
        public string $destinationCountry,
        public ?string $destinationState,
        public ?string $destinationPostalCode,
        public ?string $destinationCity,
        public array $lines,
        public ?string $warehouseCode = null,
        public ?string $houseNumber = null,
    ) {}
}
