<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\PricingRule;
use App\Support\Money\Money;

/**
 * The full derivation of a retail price, kept so admin can see exactly how a
 * number was produced and why a floor warning fired.
 */
final readonly class PriceBreakdown
{
    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public Money $cost,
        public Money $retail,
        public Money $estimatedShippingCost,
        public Money $estimatedPackagingCost,
        public Money $estimatedGatewayFee,
        public Money $contribution,
        public Money $minContribution,
        public ?PricingRule $rule,
        public bool $isManualOverride,
        public bool $breachesFloor,
        public array $warnings = [],
    ) {}

    /**
     * Margin is contribution as a share of the retail price.
     * Markup is contribution as a share of cost. They are different numbers
     * and are reported separately so they cannot be confused.
     */
    public function marginPercent(): ?float
    {
        if ($this->retail->minor === 0) {
            return null;
        }

        return round($this->contribution->minor / $this->retail->minor * 100, 2);
    }

    public function markupPercent(): ?float
    {
        if ($this->cost->minor === 0) {
            return null;
        }

        return round(($this->retail->minor - $this->cost->minor) / $this->cost->minor * 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cost_minor' => $this->cost->minor,
            'retail_minor' => $this->retail->minor,
            'estimated_shipping_cost_minor' => $this->estimatedShippingCost->minor,
            'estimated_packaging_cost_minor' => $this->estimatedPackagingCost->minor,
            'estimated_gateway_fee_minor' => $this->estimatedGatewayFee->minor,
            'contribution_minor' => $this->contribution->minor,
            'min_contribution_minor' => $this->minContribution->minor,
            'currency' => $this->retail->currency,
            'margin_percent' => $this->marginPercent(),
            'markup_percent' => $this->markupPercent(),
            'rule' => $this->rule?->name,
            'rule_strategy' => $this->rule?->strategy,
            'is_manual_override' => $this->isManualOverride,
            'breaches_floor' => $this->breachesFloor,
            'warnings' => $this->warnings,
        ];
    }
}
