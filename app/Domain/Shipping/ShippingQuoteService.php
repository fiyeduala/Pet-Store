<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Domain\Supplier\DTO\ShippingQuoteRequest;
use App\Domain\Supplier\DTO\SupplierShippingOption;
use App\Domain\Supplier\Exceptions\SupplierException;
use App\Domain\Supplier\Services\SupplierRegistry;
use App\Models\Market;
use App\Models\ShippingQuote;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Produces the shipping choices a shopper sees.
 *
 * Three owner-controlled modes are supported and can coexist:
 *   quoted          — the carrier's actual quoted price is charged
 *   flat            — a flat price per zone is charged
 *   free_threshold  — free above a configured order value
 *
 * A promotional or flat price only changes what the CUSTOMER pays. The
 * underlying supplier cost is always quoted and stored anyway, so margin
 * reporting stays honest and carrier eligibility is never faked.
 */
class ShippingQuoteService
{
    public function __construct(
        private readonly SupplierRegistry $suppliers,
        private readonly WarehouseSelector $warehouses,
    ) {}

    /**
     * Quote every parcel in a plan and return the customer-facing options.
     *
     * @param  array<string, mixed>  $destination  keys: country_code, state, postal_code, city
     */
    public function quote(
        FulfilmentPlan $plan,
        Market $market,
        array $destination,
        Money $merchandiseSubtotal,
        ?Money $discountApplied = null,
    ): ShippingQuoteSet {
        $zone = $this->resolveZone($market, $destination);

        if ($zone === null) {
            return ShippingQuoteSet::blocked(
                'We do not currently ship to this destination.'
            );
        }

        if ($zone->is_excluded) {
            return ShippingQuoteSet::blocked(
                $zone->exclusion_message ?: 'We are not able to ship to this destination.'
            );
        }

        if ($zone->blocks_po_boxes && $this->looksLikePoBox($destination)) {
            return ShippingQuoteSet::blocked(
                'The available carrier services for this destination cannot deliver to PO boxes. Please give a street address.'
            );
        }

        if ($plan->parcels === []) {
            return ShippingQuoteSet::blocked(
                $plan->reason ?: 'No warehouse can currently fulfil these items.'
            );
        }

        $group = (string) Str::uuid();
        $quotes = [];

        foreach ($plan->parcels as $index => $parcel) {
            $supplierOptions = $this->quoteParcel($parcel, $destination);

            if ($supplierOptions === []) {
                // No eligible carrier service for this parcel. A flat rate
                // cannot conjure one into existence.
                return ShippingQuoteSet::blocked(sprintf(
                    'No carrier service is available from %s to this address.',
                    $parcel->warehouse->label()
                ));
            }

            foreach ($supplierOptions as $option) {
                $quotes[] = $this->persistQuote(
                    group: $group,
                    parcelIndex: $index,
                    option: $option,
                    parcel: $parcel,
                    market: $market,
                    destination: $destination,
                    zone: $zone,
                    merchandiseSubtotal: $merchandiseSubtotal,
                    discountApplied: $discountApplied,
                );
            }
        }

        return new ShippingQuoteSet(
            group: $group,
            quotes: collect($quotes),
            parcelCount: $plan->parcelCount(),
            isSplit: $plan->isSplit(),
        );
    }

    /**
     * @param  array<string, mixed>  $destination
     * @return array<int, SupplierShippingOption>
     */
    private function quoteParcel(FulfilmentParcel $parcel, array $destination): array
    {
        $adapter = $this->suppliers->for($parcel->warehouse->supplier);

        try {
            return $adapter->quoteShipping(new ShippingQuoteRequest(
                destinationCountry: (string) $destination['country_code'],
                destinationState: $destination['state'] ?? null,
                destinationPostalCode: $destination['postal_code'] ?? null,
                destinationCity: $destination['city'] ?? null,
                lines: $parcel->toSupplierLines(),
                warehouseCode: $parcel->warehouse->code,
            ));
        } catch (SupplierException) {
            // A supplier outage must not silently become a made-up rate.
            // Returning none makes the caller show a blocked state.
            return [];
        }
    }

    /**
     * Apply the owner's charging mode to the carrier's cost and persist the
     * result with full provenance.
     *
     * @param  array<string, mixed>  $destination
     */
    private function persistQuote(
        string $group,
        int $parcelIndex,
        SupplierShippingOption $option,
        FulfilmentParcel $parcel,
        Market $market,
        array $destination,
        ShippingZone $zone,
        Money $merchandiseSubtotal,
        ?Money $discountApplied,
    ): ShippingQuote {
        $rate = $zone->rates->first();
        $supplierCost = $option->cost;

        [$charged, $rateSource] = $this->applyChargingMode($rate, $supplierCost, $merchandiseSubtotal, $discountApplied, $market);

        // Split shipments: the free-shipping threshold and flat rate apply to
        // the ORDER, not to each parcel, so only the first parcel carries the
        // charge. Otherwise a two-warehouse order would be billed twice.
        if ($parcelIndex > 0 && $rate?->mode !== ShippingRate::MODE_QUOTED) {
            $charged = Money::zero($market->currency);
        }

        $handlingMin = $rate?->handling_min_days ?? (int) settings('shipping.handling_min_days', 1) ?: null;
        $handlingMax = $rate?->handling_max_days ?? (int) settings('shipping.handling_max_days', 3) ?: null;

        // Where the supplier published no estimate, fall back to the owner's
        // explicit policy if one exists. We never invent a number.
        $useOwnerPolicy = ! $option->hasEstimate() && $rate?->hasOwnerPolicyEstimate();

        return ShippingQuote::create([
            'quote_group' => $group,
            'destination_hash' => $this->destinationHash($destination),
            'market_id' => $market->id,
            'warehouse_id' => $parcel->warehouse->id,
            'service_code' => $option->serviceCode,
            'service_name' => $option->serviceName,
            'amount_minor' => $charged->minor,
            'currency' => $market->currency,
            'supplier_cost_minor' => $supplierCost->currency === $market->currency ? $supplierCost->minor : null,

            'estimate_min' => $useOwnerPolicy ? $rate->policy_min_days : $option->estimateMin,
            'estimate_max' => $useOwnerPolicy ? $rate->policy_max_days : $option->estimateMax,
            'estimate_unit' => $useOwnerPolicy ? $rate->policy_day_unit : $option->estimateUnit,
            'estimate_type' => $useOwnerPolicy ? $rate->policy_estimate_type : $option->estimateType,
            'handling_min_days' => $handlingMin,
            'handling_max_days' => $handlingMax,
            'estimate_source' => match (true) {
                $option->hasEstimate() => ShippingQuote::SOURCE_SUPPLIER,
                (bool) $useOwnerPolicy => ShippingQuote::SOURCE_OWNER_POLICY,
                default => ShippingQuote::SOURCE_UNAVAILABLE,
            },
            'rate_source' => $rateSource,

            'is_demo' => $this->suppliers->for($parcel->warehouse->supplier)->isDemo(),
            'quoted_at' => now(),
            'expires_at' => now()->addMinutes((int) config('petstore.shipping.quote_ttl_minutes', 20)),
            'raw_payload' => $option->raw,
            'line_allocation' => [
                'parcel_index' => $parcelIndex,
                'warehouse_code' => $parcel->warehouse->code,
                'lines' => array_map(fn (array $l) => [
                    'variant_id' => $l['variant']->id,
                    'sku' => $l['variant']->sku,
                    'quantity' => $l['quantity'],
                ], $parcel->lines),
            ],
        ]);
    }

    /**
     * @return array{0: Money, 1: string}
     */
    private function applyChargingMode(
        ?ShippingRate $rate,
        Money $supplierCost,
        Money $merchandiseSubtotal,
        ?Money $discountApplied,
        Market $market,
    ): array {
        $currency = $market->currency;

        if ($rate === null) {
            return [$this->convertOrZero($supplierCost, $currency), ShippingQuote::SOURCE_SUPPLIER];
        }

        // Free-shipping thresholds are evaluated AFTER discounts, so a coupon
        // cannot push an order over the line it no longer clears. This is
        // documented in docs/admin-guide.md.
        $qualifyingTotal = $discountApplied !== null
            ? $merchandiseSubtotal->minus($discountApplied)
            : $merchandiseSubtotal;

        return match ($rate->mode) {
            ShippingRate::MODE_FLAT => [
                Money::ofMinor((int) ($rate->getRawOriginal('flat_amount_minor') ?? 0), $currency),
                'flat_rate',
            ],

            ShippingRate::MODE_FREE_THRESHOLD => (function () use ($rate, $qualifyingTotal, $currency, $supplierCost) {
                $threshold = (int) ($rate->getRawOriginal('free_threshold_minor') ?? 0);

                if ($qualifyingTotal->minor >= $threshold) {
                    return [Money::zero($currency), 'free_threshold'];
                }

                $flat = $rate->getRawOriginal('flat_amount_minor');

                return $flat !== null
                    ? [Money::ofMinor((int) $flat, $currency), 'flat_rate']
                    : [$this->convertOrZero($supplierCost, $currency), ShippingQuote::SOURCE_SUPPLIER];
            })(),

            default => [$this->convertOrZero($supplierCost, $currency), ShippingQuote::SOURCE_SUPPLIER],
        };
    }

    private function convertOrZero(Money $cost, string $currency): Money
    {
        // Cross-currency carrier costs need a configured conversion policy.
        // Until one exists we do not guess an exchange rate.
        return $cost->currency === $currency ? $cost : Money::zero($currency);
    }

    /**
     * @param  array<string, mixed>  $destination
     */
    public function resolveZone(Market $market, array $destination): ?ShippingZone
    {
        return $market->shippingZones()
            ->with('rates')
            ->where('is_active', true)
            ->orderBy('priority')
            ->get()
            ->first(fn (ShippingZone $zone) => $zone->matches(
                (string) $destination['country_code'],
                $destination['state'] ?? null,
                $destination['postal_code'] ?? null,
            ));
    }

    /**
     * @param  array<string, mixed>  $destination
     */
    public function destinationHash(array $destination): string
    {
        return hash('sha256', implode('|', [
            strtoupper((string) ($destination['country_code'] ?? '')),
            strtoupper((string) ($destination['state'] ?? '')),
            strtoupper(preg_replace('/\s+/', '', (string) ($destination['postal_code'] ?? '')) ?? ''),
        ]));
    }

    /**
     * @param  array<string, mixed>  $destination
     */
    private function looksLikePoBox(array $destination): bool
    {
        $line = strtolower(trim(($destination['line1'] ?? '').' '.($destination['line2'] ?? '')));

        return (bool) preg_match('/\b(p\.?\s*o\.?\s*box|post\s+office\s+box)\b/i', $line);
    }

    /**
     * Reload a previously stored, still-valid quote set.
     */
    public function findValidSet(string $group): ?ShippingQuoteSet
    {
        $quotes = ShippingQuote::query()->where('quote_group', $group)->get();

        if ($quotes->isEmpty() || $quotes->contains(fn (ShippingQuote $q) => $q->isExpired())) {
            return null;
        }

        $parcelCount = $quotes->pluck('line_allocation.parcel_index')->unique()->count();

        return new ShippingQuoteSet($group, $quotes, $parcelCount, $parcelCount > 1);
    }
}
