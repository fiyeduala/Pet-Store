<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

final readonly class ProductSearchResult
{
    /**
     * @param  array<int, SupplierProduct>  $products
     */
    public function __construct(
        public array $products,
        public int $page,
        public int $pageSize,
        public ?int $totalCount = null,
        public bool $hasMore = false,
    ) {}
}
