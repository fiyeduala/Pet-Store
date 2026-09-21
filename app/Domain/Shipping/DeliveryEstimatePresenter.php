<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Models\ShippingQuote;
use App\Models\Shipment;

/**
 * Turns a stored estimate into customer-facing wording.
 *
 * The rules this enforces, all of them deliberate:
 *
 *  - If the supplier gave no estimate, we say so. We never fill the gap
 *    with a plausible-looking number.
 *  - "Business days" and "days" are never used interchangeably.
 *  - When the unit is unknown we say "days" is unknown rather than picking
 *    one, because the two differ by up to 40% over a week.
 *  - Transit time is labelled as transit time. Handling time is stated
 *    separately and is never folded into it silently.
 *  - No universal delivery promise is ever rendered here.
 */
class DeliveryEstimatePresenter
{
    public function forQuote(ShippingQuote $quote): string
    {
        return $this->describe(
            $quote->estimate_min,
            $quote->estimate_max,
            $quote->estimate_unit,
            $quote->estimate_type,
            $quote->handling_min_days,
            $quote->handling_max_days,
            $quote->estimate_source,
        );
    }

    public function forShipment(Shipment $shipment): string
    {
        return $this->describe(
            $shipment->estimate_min,
            $shipment->estimate_max,
            $shipment->estimate_unit,
            $shipment->estimate_type,
            null,
            null,
            ShippingQuote::SOURCE_SUPPLIER,
        );
    }

    /**
     * Cautious wording shown before a shopper has given us a destination.
     * Delivery depends on where it is going, so we do not pretend otherwise.
     */
    public function preDestinationMessage(): string
    {
        return (string) settings(
            'shipping.pre_destination_message',
            'Delivery time depends on your address and which warehouse has stock. Enter your ZIP code for an estimate.'
        );
    }

    public function unavailableMessage(): string
    {
        return (string) settings(
            'shipping.estimate_unavailable_message',
            'A delivery estimate is not available for this service.'
        );
    }

    private function describe(
        ?int $min,
        ?int $max,
        ?string $unit,
        ?string $type,
        ?int $handlingMin,
        ?int $handlingMax,
        ?string $source,
    ): string {
        if ($min === null || $unit === null || $unit === ShippingQuote::UNIT_UNKNOWN) {
            // We know how long nothing. Say that, plainly.
            return $min !== null
                ? $this->unknownUnitPhrase($min, $max, $type)
                : $this->unavailableMessage();
        }

        $range = $this->range($min, $max);
        $unitLabel = $unit === ShippingQuote::UNIT_BUSINESS_DAYS ? 'business days' : 'days';

        $phrase = match ($type) {
            ShippingQuote::TYPE_TRANSIT => "{$range} {$unitLabel} in transit",
            ShippingQuote::TYPE_PROCESSING => "{$range} {$unitLabel} to process",
            ShippingQuote::TYPE_TOTAL => "{$range} {$unitLabel} to arrive",
            default => "{$range} {$unitLabel}",
        };

        // Handling is additive and is always named as a separate step.
        if ($handlingMin !== null && $type !== ShippingQuote::TYPE_TOTAL) {
            $handlingRange = $this->range($handlingMin, $handlingMax);
            $phrase .= ", plus {$handlingRange} business days handling before dispatch";
        }

        if ($source === ShippingQuote::SOURCE_OWNER_POLICY) {
            $phrase .= ' (store estimate)';
        }

        return ucfirst($phrase);
    }

    private function unknownUnitPhrase(int $min, ?int $max, ?string $type): string
    {
        $range = $this->range($min, $max);
        $what = $type === ShippingQuote::TYPE_TRANSIT ? 'transit' : 'delivery';

        // The supplier gave a number but not its unit. Reporting it without
        // the unit would be misleading, so the ambiguity is stated.
        return "Carrier quotes {$range} for {$what}, but did not specify whether these are calendar or business days.";
    }

    private function range(int $min, ?int $max): string
    {
        return ($max === null || $max === $min) ? (string) $min : "{$min}–{$max}";
    }
}
