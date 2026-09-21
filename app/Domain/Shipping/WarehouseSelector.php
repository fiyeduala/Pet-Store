<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Models\Market;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Support\Collection;

/**
 * Chooses which warehouse fulfils which lines.
 *
 * The strategy is deterministic and documented so two runs over the same
 * data always produce the same plan:
 *
 *   1. Only warehouses that are enabled, belong to the supplier, and are
 *      eligible for the market are considered. For a domestic-only order
 *      that means the market's preferred countries only — running out of US
 *      stock does NOT silently promote a Chinese warehouse.
 *   2. Warehouses are ranked by `priority` ascending, then by how many of
 *      the order's lines they can cover in full (most first), then by code.
 *      Covering more lines in one warehouse means fewer parcels.
 *   3. Lines are assigned greedily to the highest-ranked warehouse that has
 *      confirmed stock. Anything left over becomes a second parcel.
 *
 * Note that proximity is NOT part of the ranking. A nearer warehouse is not
 * reliably faster; only a quoted service tells us that.
 */
class WarehouseSelector
{
    /**
     * @param  Collection<int, array{variant: ProductVariant, quantity: int}>  $lines
     */
    public function plan(Collection $lines, Market $market, bool $allowOverseas = false): FulfilmentPlan
    {
        $warehouses = $this->eligibleWarehouses($market, $allowOverseas);

        if ($warehouses->isEmpty()) {
            return new FulfilmentPlan([], $lines->all(), 'No enabled warehouse is eligible for this destination.');
        }

        $remaining = $lines->mapWithKeys(fn (array $line) => [
            $line['variant']->id => ['variant' => $line['variant'], 'quantity' => $line['quantity']],
        ])->all();

        $ranked = $this->rank($warehouses, $remaining);
        $parcels = [];

        foreach ($ranked as $warehouse) {
            if ($remaining === []) {
                break;
            }

            $assigned = [];

            foreach ($remaining as $variantId => $line) {
                $available = $line['variant']->availableStock([$warehouse->id]);

                if ($available <= 0) {
                    continue;
                }

                $take = min($available, $line['quantity']);
                $assigned[] = ['variant' => $line['variant'], 'quantity' => $take];

                if ($take >= $line['quantity']) {
                    unset($remaining[$variantId]);
                } else {
                    $remaining[$variantId]['quantity'] -= $take;
                }
            }

            if ($assigned !== []) {
                $parcels[] = new FulfilmentParcel($warehouse, $assigned);
            }
        }

        $unfulfillable = array_values($remaining);

        return new FulfilmentPlan(
            parcels: $parcels,
            unfulfillable: $unfulfillable,
            reason: $unfulfillable === []
                ? null
                : 'Some items have no confirmed stock in an eligible warehouse.',
        );
    }

    /**
     * @return Collection<int, Warehouse>
     */
    public function eligibleWarehouses(Market $market, bool $allowOverseas = false): Collection
    {
        $query = Warehouse::query()->where('is_enabled', true);

        if (! $allowOverseas) {
            // Domestic-only is the default. Overseas fulfilment requires a
            // deliberate setting plus pre-purchase disclosure.
            $query->whereIn('country_code', array_map('strtoupper', $market->preferredCountries()));
        }

        return $query->orderBy('priority')->orderBy('code')->get();
    }

    /**
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $lines
     * @return array<int, Warehouse>
     */
    private function rank(Collection $warehouses, array $lines): array
    {
        $scored = $warehouses->map(function (Warehouse $warehouse) use ($lines) {
            $covered = 0;

            foreach ($lines as $line) {
                if ($line['variant']->availableStock([$warehouse->id]) >= $line['quantity']) {
                    $covered++;
                }
            }

            return ['warehouse' => $warehouse, 'covered' => $covered];
        });

        return $scored
            ->sortBy([
                fn (array $a, array $b) => $a['warehouse']->priority <=> $b['warehouse']->priority,
                fn (array $a, array $b) => $b['covered'] <=> $a['covered'],
                fn (array $a, array $b) => strcmp($a['warehouse']->code, $b['warehouse']->code),
            ])
            ->pluck('warehouse')
            ->all();
    }
}
