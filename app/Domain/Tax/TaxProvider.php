<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Models\Market;
use App\Support\Money\Money;

/**
 * Pluggable tax calculation.
 *
 * The default implementation applies only rules the owner has explicitly
 * configured and marked active. There is deliberately no built-in "US sales
 * tax" table: correct US nexus and rate determination depends on the
 * business's own registrations, and inventing one would be worse than
 * charging nothing.
 */
interface TaxProvider
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $destination
     */
    public function calculate(
        Market $market,
        array $destination,
        Money $taxableSubtotal,
        Money $shipping,
    ): TaxResult;

    /**
     * Whether this provider is configured well enough to be trusted.
     */
    public function isConfigured(Market $market): bool;
}
