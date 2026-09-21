<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

final readonly class SupplierProduct
{
    /**
     * @param  array<int, SupplierVariant>  $variants
     * @param  array<int, string>  $images
     * @param  array<string, mixed>  $raw   preserved verbatim for auditing
     */
    public function __construct(
        public string $supplierProductId,
        public string $name,
        public ?string $description = null,
        public ?string $categoryName = null,
        public array $variants = [],
        public array $images = [],
        public array $raw = [],
    ) {}
}
