<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment;

use App\Domain\Shipping\ShippingQuoteService;
use App\Domain\Supplier\DTO\ShippingQuoteRequest;
use App\Domain\Supplier\Exceptions\SupplierException;
use App\Domain\Supplier\Services\SupplierRegistry;
use App\Models\Fulfilment;
use App\Models\FulfilmentItem;
use App\Support\Money\Money;

/**
 * Re-checks the world immediately before a supplier order is placed.
 *
 * The customer has already paid by this point, so anything that has moved
 * materially since then is a decision for a human, not something to absorb
 * quietly or pass on as a surprise charge. What counts as "material" is
 * owner-configurable.
 */
class PreflightChecker
{
    public function __construct(
        private readonly SupplierRegistry $suppliers,
        private readonly ShippingQuoteService $quotes,
    ) {}

    public function check(Fulfilment $fulfilment): PreflightResult
    {
        $findings = [];
        $adapter = $this->suppliers->for($fulfilment->supplier);
        $order = $fulfilment->order;

        $items = $fulfilment->items()->with('orderItem.variant')->get();

        /* ---- Stock ---- */
        $variantIds = $items
            ->map(fn (FulfilmentItem $i) => $i->orderItem->supplier_variant_id)
            ->filter()
            ->values()
            ->all();

        $stockByVariant = [];

        try {
            $stockByVariant = $adapter->fetchStock($variantIds);
        } catch (SupplierException $e) {
            $findings[] = [
                'type' => 'stock_unreadable',
                'detail' => 'Could not read current stock from the supplier: '.$e->getMessage(),
            ];
        }

        foreach ($items as $item) {
            $vid = (string) $item->orderItem->supplier_variant_id;
            $readings = $stockByVariant[$vid] ?? null;

            if ($readings === null) {
                continue;
            }

            $warehouseCode = $fulfilment->warehouse?->code;

            $available = collect($readings)
                ->filter(fn ($r) => $warehouseCode === null || $r->warehouseCode === $warehouseCode)
                ->filter(fn ($r) => $r->isKnown())
                ->sum(fn ($r) => (int) $r->quantity);

            $anyKnown = collect($readings)->contains(fn ($r) => $r->isKnown());

            if (! $anyKnown) {
                $findings[] = [
                    'type' => 'stock_unknown',
                    'detail' => sprintf('%s: the supplier reports no stock figure for this variant.', $item->orderItem->sku),
                ];

                continue;
            }

            if ($available < $item->quantity) {
                $findings[] = [
                    'type' => 'stock_changed',
                    'detail' => sprintf(
                        '%s: %d needed but only %d available at %s.',
                        $item->orderItem->sku,
                        $item->quantity,
                        $available,
                        $fulfilment->warehouse?->label() ?? 'the selected warehouse'
                    ),
                ];
            }
        }

        /* ---- Cost and service ---- */
        $serviceCode = null;
        $toleranceBp = (int) settings('fulfilment.cost_increase_tolerance_bp', 1000); // 10%

        try {
            $options = $adapter->quoteShipping(new ShippingQuoteRequest(
                destinationCountry: (string) data_get($order->shipping_address, 'country_code', 'US'),
                destinationState: data_get($order->shipping_address, 'state'),
                destinationPostalCode: data_get($order->shipping_address, 'postal_code'),
                destinationCity: data_get($order->shipping_address, 'city'),
                lines: $items->map(fn (FulfilmentItem $i) => [
                    'supplier_variant_id' => (string) $i->orderItem->supplier_variant_id,
                    'quantity' => $i->quantity,
                    'weight_grams' => $i->orderItem->variant?->billableWeightGrams() ?? 500,
                ])->values()->all(),
                warehouseCode: $fulfilment->warehouse?->code,
            ));

            if ($options === []) {
                $findings[] = [
                    'type' => 'service_unavailable',
                    'detail' => 'No carrier service is currently available for this parcel and address.',
                ];
            } else {
                $cheapest = collect($options)->sortBy(fn ($o) => $o->cost->minor)->first();
                $serviceCode = $cheapest->serviceCode;

                $quoted = (int) ($order->getRawOriginal('supplier_shipping_cost_minor') ?? 0);

                if ($quoted > 0 && $cheapest->cost->currency === $order->currency) {
                    $increase = $cheapest->cost->minor - $quoted;

                    if ($increase > 0 && ($increase * 10_000 / max(1, $quoted)) > $toleranceBp) {
                        $findings[] = [
                            'type' => 'cost_changed',
                            'detail' => sprintf(
                                'Shipping cost rose from %s to %s since the customer paid.',
                                Money::ofMinor($quoted, $order->currency)->format(),
                                $cheapest->cost->format()
                            ),
                        ];
                    }
                }
            }
        } catch (SupplierException $e) {
            $findings[] = [
                'type' => 'service_unavailable',
                'detail' => 'Could not re-quote shipping before submission: '.$e->getMessage(),
            ];
        }

        return new PreflightResult(
            passed: $findings === [],
            findings: $findings,
            serviceCode: $serviceCode,
        );
    }
}
