<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Models\Market;
use App\Support\Money\Money;

/**
 * Entry point for tax. Delegates to the market's configured provider.
 *
 * A market's tax obligations are not determined by its currency or by the
 * delivery country alone, so nothing is inferred here: the market must name
 * a mode and, for manual mode, the owner must configure rules.
 */
class TaxCalculator
{
    /**
     * @param  array<string, TaxProvider>  $providers  keyed by provider name
     */
    public function __construct(
        private readonly ManualTaxProvider $manual,
        private readonly array $providers = [],
    ) {}

    /**
     * @param  array<string, mixed>  $destination
     */
    public function calculate(Market $market, array $destination, Money $taxableSubtotal, Money $shipping): TaxResult
    {
        $provider = $this->providerFor($market);

        return $provider->calculate($market, $destination, $taxableSubtotal, $shipping);
    }

    public function providerFor(Market $market): TaxProvider
    {
        if ($market->tax_mode === 'provider' && $market->tax_provider !== null) {
            return $this->providers[$market->tax_provider]
                ?? throw new \RuntimeException(
                    "Market [{$market->code}] names tax provider [{$market->tax_provider}] but no such provider is registered."
                );
        }

        return $this->manual;
    }

    /**
     * Admin-facing summary of how tax is currently set up.
     *
     * @return array<string, mixed>
     */
    public function status(Market $market): array
    {
        $provider = $this->providerFor($market);

        return [
            'mode' => $market->tax_mode,
            'provider' => $provider->name(),
            'configured' => $provider->isConfigured($market),
            'active_rules' => $market->taxRules()->where('is_active', true)->count(),
            'warning' => $market->tax_mode === 'disabled'
                ? 'Tax is switched off for this market. Confirm with your accountant that no tax is due before selling.'
                : ($provider->isConfigured($market)
                    ? null
                    : 'Tax mode is set but no active rules exist. Orders will be charged zero tax.'),
        ];
    }
}
