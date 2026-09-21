<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Models\ProductVariant;

final readonly class FulfilmentPlan
{
    /**
     * @param  array<int, FulfilmentParcel>  $parcels
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $unfulfillable
     */
    public function __construct(
        public array $parcels,
        public array $unfulfillable = [],
        public ?string $reason = null,
    ) {}

    public function isComplete(): bool
    {
        return $this->unfulfillable === [] && $this->parcels !== [];
    }

    public function isSplit(): bool
    {
        return count($this->parcels) > 1;
    }

    public function parcelCount(): int
    {
        return count($this->parcels);
    }
}
