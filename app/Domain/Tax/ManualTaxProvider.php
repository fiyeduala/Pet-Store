<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Models\Market;
use App\Models\TaxRule;
use App\Support\Money\Money;

/**
 * Applies the owner's explicitly configured tax rules and nothing else.
 *
 * Rates are held in basis points and applied with exact integer arithmetic.
 * If no rule matches, the result is zero tax and `unconfigured` is set, so
 * admin can distinguish "correctly zero-rated" from "nobody set this up".
 * The application never claims automatic US-wide tax compliance.
 */
class ManualTaxProvider implements TaxProvider
{
    public function name(): string
    {
        return 'Manual rules';
    }

    public function isConfigured(Market $market): bool
    {
        return $market->tax_mode === 'manual'
            && $market->taxRules()->where('is_active', true)->exists();
    }

    public function calculate(Market $market, array $destination, Money $taxableSubtotal, Money $shipping): TaxResult
    {
        $currency = $market->currency;

        if ($market->tax_mode === 'disabled') {
            return TaxResult::none($currency, 'Tax calculation is disabled for this market.');
        }

        $rules = $market->taxRules()
            ->where('is_active', true)
            ->get()
            ->filter(fn (TaxRule $rule) => $rule->matches(
                (string) ($destination['country_code'] ?? ''),
                $destination['state'] ?? null,
                $destination['postal_code'] ?? null,
            ));

        if ($rules->isEmpty()) {
            return TaxResult::none(
                $currency,
                'No active tax rule matches this destination. Configure one in Admin → Markets & Tax if tax is due here.',
                unconfigured: $market->taxRules()->where('is_active', true)->doesntExist(),
            );
        }

        $total = Money::zero($currency);
        $lines = [];

        foreach ($rules as $rule) {
            $base = $rule->applies_to_shipping
                ? $taxableSubtotal->plus($shipping)
                : $taxableSubtotal;

            $amount = $base->percentageOfBasisPoints($rule->rate_basis_points);
            $total = $total->plus($amount);

            $lines[] = [
                'name' => $rule->name,
                'rate' => $rule->ratePercentLabel(),
                'amount_minor' => $amount->minor,
            ];
        }

        return new TaxResult(
            amount: $total,
            lines: $lines,
            basis: 'manual_rules',
            unconfigured: false,
        );
    }
}
