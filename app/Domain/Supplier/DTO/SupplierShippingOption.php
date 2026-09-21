<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

use App\Models\ShippingQuote;
use App\Support\Money\Money;

/**
 * One carrier service the supplier offered.
 *
 * The estimate carries its unit and its type. When the supplier did not
 * supply them they stay `unknown`, and the storefront says the estimate is
 * unavailable rather than inventing one.
 */
final readonly class SupplierShippingOption
{
    public function __construct(
        public string $serviceName,
        public ?string $serviceCode,
        public Money $cost,
        public ?int $estimateMin = null,
        public ?int $estimateMax = null,
        public string $estimateUnit = ShippingQuote::UNIT_UNKNOWN,
        public string $estimateType = ShippingQuote::TYPE_UNKNOWN,
        public ?string $warehouseCode = null,
        public array $raw = [],
    ) {}

    public function hasEstimate(): bool
    {
        return $this->estimateMin !== null && $this->estimateUnit !== ShippingQuote::UNIT_UNKNOWN;
    }
}
