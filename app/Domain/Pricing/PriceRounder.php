<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\PricingRule;
use App\Support\Money\Money;

/**
 * Applies a rule's rounding policy to a raw computed price.
 *
 * Rounding happens once, at the very end of the calculation, so
 * intermediate values never accumulate rounding error.
 *
 * A charm ending (e.g. always end in .99) and a rounding increment are two
 * ways of expressing the same intent, so they are never both applied to
 * the same number. Doing so would round twice: $8.16 would go up to $9.00
 * and then out to $9.99, nearly a dollar above what the rule asked for.
 * When an ending is configured it wins, and the price is snapped onto the
 * grid of values carrying that ending.
 */
class PriceRounder
{
    public function apply(Money $price, PricingRule $rule): Money
    {
        $minor = $rule->rounding_ending_minor !== null
            ? $this->applyCharmEnding($price->minor, $rule)
            : $this->applyIncrement($price->minor, $rule);

        return Money::ofMinor(max(0, $minor), $price->currency);
    }

    /**
     * Snap onto the nearest value ending in the configured minor units,
     * honouring the rule's direction.
     *
     * With an ending of 99 and mode "up": 816 -> 899, 899 -> 899, 901 -> 999.
     */
    private function applyCharmEnding(int $minor, PricingRule $rule): int
    {
        $ending = $rule->rounding_ending_minor;

        // The grid step is the increment when one is set, otherwise a whole
        // unit of the major currency.
        $step = ($rule->rounding_increment_minor !== null && $rule->rounding_increment_minor > 0)
            ? $rule->rounding_increment_minor
            : 100;

        $step = max($step, $ending + 1);

        $below = intdiv($minor, $step) * $step + $ending;
        $above = $below + $step;

        // Exactly on the grid already: leave it alone whatever the mode.
        if ($below === $minor) {
            return $minor;
        }

        return match ($rule->rounding_mode) {
            'down' => $below <= $minor ? $below : $below - $step,
            'nearest' => (abs($minor - $below) <= abs($above - $minor) && $below >= 0) ? $below : $above,
            // "up" and "none" both refuse to price below the computed value,
            // because rounding down silently erodes the configured margin.
            default => $below >= $minor ? $below : $above,
        };
    }

    private function applyIncrement(int $minor, PricingRule $rule): int
    {
        $increment = $rule->rounding_increment_minor;

        if ($increment === null || $increment <= 0) {
            return $minor;
        }

        return match ($rule->rounding_mode) {
            'up' => (int) (ceil($minor / $increment) * $increment),
            'down' => (int) (floor($minor / $increment) * $increment),
            'nearest' => (int) (round($minor / $increment) * $increment),
            default => $minor,
        };
    }
}
