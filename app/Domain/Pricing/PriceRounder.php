<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\PricingRule;
use App\Support\Money\Money;

/**
 * Applies a rule's rounding policy to a raw computed price.
 *
 * Rounding happens once, at the end of the calculation, so intermediate
 * values never accumulate rounding error.
 */
class PriceRounder
{
    public function apply(Money $price, PricingRule $rule): Money
    {
        $minor = $price->minor;

        $increment = $rule->rounding_increment_minor;

        if ($increment !== null && $increment > 0) {
            $minor = match ($rule->rounding_mode) {
                'up' => (int) (ceil($minor / $increment) * $increment),
                'down' => (int) (floor($minor / $increment) * $increment),
                'nearest' => (int) (round($minor / $increment) * $increment),
                default => $minor,
            };
        }

        // A charm ending replaces the trailing minor units, e.g. force x.99.
        if ($rule->rounding_ending_minor !== null) {
            $ending = $rule->rounding_ending_minor;
            $whole = intdiv($minor, 100) * 100;
            $candidate = $whole + $ending;

            // Never round a price down below the pre-rounding value when the
            // rule asked to round up, or we would quietly erode the margin.
            if ($candidate < $minor && $rule->rounding_mode !== 'down') {
                $candidate += 100;
            }

            $minor = $candidate;
        }

        return Money::ofMinor(max(0, $minor), $price->currency);
    }
}
