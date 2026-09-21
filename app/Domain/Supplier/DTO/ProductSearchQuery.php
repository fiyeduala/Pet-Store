<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

final readonly class ProductSearchQuery
{
    public function __construct(
        public ?string $keyword = null,
        public ?string $categoryId = null,
        public int $page = 1,
        public int $pageSize = 50,
        /** Restrict to products with stock in these warehouse countries. */
        public ?array $warehouseCountries = null,
    ) {}
}
