<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Market;
use App\Models\PricingRule;
use App\Models\ProductVariant;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;

/**
 * Turns a supplier cost into a retail price.
 *
 * Rule precedence, most specific first:
 *   1. product   (scope_id = product id)
 *   2. category  (scope_id = any category the product belongs to)
 *   3. market    (scope_id unused; market_id must match)
 *   4. global
 *
 * Within the same specificity, lower `priority` wins, then lower id. A
 * variant's manual price override sits above all of this and wins until it
 * is explicitly cleared.
 */
class PricingEngine
{
    public function __construct(private readonly PriceRounder $rounder) {}

    /**
     * Compute the price a rule would produce, ignoring any manual override.
     */
    public function computeRulePrice(ProductVariant $variant, Market $market): ?Money
    {
        $cost = $this->costOf($variant);

        if ($cost === null) {
            return null;
        }

        $rule = $this->resolveRule($variant, $market);

        if ($rule === null) {
            return null;
        }

        return $this->applyStrategy($cost, $rule, $market->currency);
    }

    /**
     * Full derivation including the contribution floor check.
     */
    public function breakdown(
        ProductVariant $variant,
        Market $market,
        ?Money $retailOverride = null,
        ?Money $shippingCost = null,
        ?Money $packagingCost = null,
        string $gatewayCode = 'paypal',
    ): PriceBreakdown {
        $currency = $market->currency;
        $cost = $this->costOf($variant) ?? Money::zero($currency);
        $rule = $this->resolveRule($variant, $market);
        $warnings = [];

        $manualMinor = $variant->getRawOriginal('manual_price_minor');
        $isManual = $retailOverride === null && $manualMinor !== null;

        if ($retailOverride !== null) {
            $retail = $retailOverride;
        } elseif ($isManual) {
            $retail = Money::ofMinor((int) $manualMinor, $variant->currency);
        } else {
            $retail = $rule !== null
                ? $this->applyStrategy($cost, $rule, $currency)
                : Money::ofMinor($variant->effectivePriceMinor() ?? 0, $variant->currency);
        }

        if ($this->costOf($variant) === null) {
            $warnings[] = 'No supplier cost is recorded for this variant, so contribution cannot be trusted.';
        }

        if ($rule === null && ! $isManual && $retailOverride === null) {
            $warnings[] = 'No pricing rule matched this variant. The stored price is being used as-is.';
        }

        $shipping = $shippingCost ?? $this->estimatedShippingCost($currency);
        $packaging = $packagingCost ?? Money::zero($currency);
        $gatewayFee = $this->estimatedGatewayFee($retail, $gatewayCode);

        // Contribution deliberately excludes advertising and overhead, which
        // are not knowable here. It is never labelled net profit.
        $contribution = $retail
            ->minus($this->inCurrency($cost, $currency))
            ->minus($shipping)
            ->minus($packaging)
            ->minus($gatewayFee);

        $floor = Money::ofMinor($rule?->min_contribution_minor ?? 0, $currency);
        $breaches = $contribution->lessThan($floor);

        if ($breaches) {
            $warnings[] = sprintf(
                'Contribution %s is below the configured floor of %s.',
                $contribution->format(),
                $floor->format()
            );
        }

        return new PriceBreakdown(
            cost: $this->inCurrency($cost, $currency),
            retail: $retail,
            estimatedShippingCost: $shipping,
            estimatedPackagingCost: $packaging,
            estimatedGatewayFee: $gatewayFee,
            contribution: $contribution,
            minContribution: $floor,
            rule: $rule,
            isManualOverride: $isManual,
            breachesFloor: $breaches,
            warnings: $warnings,
        );
    }

    /**
     * Recalculate and persist `computed_price_minor`.
     *
     * A manual override is never touched: it stays authoritative until an
     * admin clears it explicitly.
     */
    public function repriceVariant(ProductVariant $variant, Market $market): ?Money
    {
        $price = $this->computeRulePrice($variant, $market);

        if ($price === null) {
            return null;
        }

        $rule = $this->resolveRule($variant, $market);

        $variant->forceFill([
            'computed_price_minor' => $price->minor,
            'currency' => $price->currency,
            'applied_pricing_rule' => $rule?->name,
        ])->save();

        return $price;
    }

    /**
     * Find the winning rule for this variant in this market.
     */
    public function resolveRule(ProductVariant $variant, Market $market): ?PricingRule
    {
        // Category scoping needs the product and its categories. Load them
        // here rather than relying on the caller, so a single-variant
        // reprice does not silently trigger an N+1 in a bulk loop.
        $variant->loadMissing('product.categories');

        $product = $variant->product;
        $categoryIds = $product?->categories->pluck('id')->all() ?? [];

        $candidates = PricingRule::query()
            ->where('is_active', true)
            ->where(function ($q) use ($market) {
                $q->whereNull('market_id')->orWhere('market_id', $market->id);
            })
            ->where(function ($q) use ($product, $categoryIds) {
                $q->where('scope', PricingRule::SCOPE_GLOBAL)
                    ->orWhere('scope', PricingRule::SCOPE_MARKET)
                    ->orWhere(fn ($q2) => $q2->where('scope', PricingRule::SCOPE_PRODUCT)->where('scope_id', $product?->id))
                    ->orWhere(fn ($q2) => $q2->where('scope', PricingRule::SCOPE_CATEGORY)->whereIn('scope_id', $categoryIds ?: [0]));
            })
            ->get()
            ->filter(fn (PricingRule $r) => $r->isCurrentlyActive());

        // A market-scoped rule only applies when it names this market.
        $candidates = $candidates->reject(
            fn (PricingRule $r) => $r->scope === PricingRule::SCOPE_MARKET && $r->market_id !== $market->id
        );

        return $candidates
            ->sortBy([
                fn (PricingRule $a, PricingRule $b) => $b->specificity() <=> $a->specificity(),
                fn (PricingRule $a, PricingRule $b) => $a->priority <=> $b->priority,
                fn (PricingRule $a, PricingRule $b) => $a->id <=> $b->id,
            ])
            ->first();
    }

    /**
     * Apply one strategy to a cost.
     */
    private function applyStrategy(Money $cost, PricingRule $rule, string $currency): Money
    {
        $cost = $this->inCurrency($cost, $currency);

        $raw = match ($rule->strategy) {
            PricingRule::STRATEGY_FIXED_MARKUP => $cost->plus(
                Money::ofMinor((int) ($rule->markup_amount_minor ?? 0), $currency)
            ),

            // Markup: a percentage ADDED TO cost.
            PricingRule::STRATEGY_PERCENTAGE_MARKUP => $cost->multipliedByRatio(
                BigDecimal::one()->plus(BigDecimal::of((string) ($rule->markup_percentage ?? 0))->dividedBy(100, 10, RoundingMode::HALF_UP))
            ),

            // Margin: a percentage OF THE RETAIL PRICE.
            //   retail = cost / (1 - margin)
            PricingRule::STRATEGY_TARGET_MARGIN => $this->fromTargetMargin($cost, (string) ($rule->target_margin_percentage ?? 0)),

            default => throw new RuntimeException("Unknown pricing strategy [{$rule->strategy}]."),
        };

        return $this->rounder->apply($raw, $rule);
    }

    private function fromTargetMargin(Money $cost, string $marginPercentage): Money
    {
        $margin = BigDecimal::of($marginPercentage)->dividedBy(100, 10, RoundingMode::HALF_UP);

        // A 100%+ margin is unreachable; treat it as a configuration error
        // rather than dividing by zero or going negative.
        if ($margin->isGreaterThanOrEqualTo(BigDecimal::one())) {
            throw new RuntimeException('Target margin must be below 100%.');
        }

        $divisor = BigDecimal::one()->minus($margin);

        $minor = BigDecimal::of($cost->minor)
            ->dividedBy($divisor, 0, RoundingMode::HALF_UP)
            ->toInt();

        return Money::ofMinor($minor, $cost->currency);
    }

    private function costOf(ProductVariant $variant): ?Money
    {
        $minor = $variant->getRawOriginal('supplier_cost_minor');

        if ($minor === null) {
            return null;
        }

        return Money::ofMinor((int) $minor, $variant->supplier_cost_currency ?: $variant->currency);
    }

    /**
     * Costs are only ever combined with prices in the same currency.
     * Cross-currency pricing requires a deliberately configured, timestamped
     * rate, which is handled by CurrencyConverter, not silently here.
     */
    private function inCurrency(Money $money, string $currency): Money
    {
        if ($money->currency === $currency) {
            return $money;
        }

        throw new RuntimeException(sprintf(
            'Supplier cost is in %s but the market prices in %s. Configure a conversion policy before pricing this variant.',
            $money->currency,
            $currency
        ));
    }

    private function estimatedShippingCost(string $currency): Money
    {
        return Money::ofMinor((int) settings('pricing.estimated_shipping_cost_minor', 0), $currency);
    }

    /**
     * Estimated processing fee, used only for contribution warnings. The
     * actual fee replaces it once the provider reports it.
     */
    public function estimatedGatewayFee(Money $retail, string $gatewayCode): Money
    {
        $config = config("petstore.payments.fee_estimates.{$gatewayCode}")
            ?? ['basis_points' => 0, 'fixed_minor' => 0];

        return $retail
            ->percentageOfBasisPoints((int) $config['basis_points'])
            ->plus(Money::ofMinor((int) $config['fixed_minor'], $retail->currency));
    }
}
