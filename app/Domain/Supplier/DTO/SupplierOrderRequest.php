<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

final readonly class SupplierOrderRequest
{
    /**
     * @param  array<int, array{supplier_variant_id: string, quantity: int}>  $lines
     * @param  array<string, mixed>  $shippingAddress
     */
    public function __construct(
        public string $internalReference,
        public array $lines,
        public array $shippingAddress,
        public ?string $shippingServiceCode,
        public ?string $warehouseCode,
        public ?string $packagingId = null,
        public ?string $note = null,
    ) {}
}
