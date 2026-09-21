<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Models\ShippingQuote;
use Illuminate\Support\Collection;

/**
 * The quotes offered for one cart/destination combination.
 *
 * When an order splits across warehouses, the shopper picks one service
 * *per parcel group*, and the parcels are shown separately so the split is
 * visible before purchase rather than discovered afterwards.
 */
final readonly class ShippingQuoteSet
{
    /**
     * @param  Collection<int, ShippingQuote>  $quotes
     */
    public function __construct(
        public string $group,
        public Collection $quotes,
        public int $parcelCount = 1,
        public bool $isSplit = false,
        public ?string $blockedReason = null,
    ) {}

    public static function blocked(string $reason): self
    {
        return new self('', collect(), 0, false, $reason);
    }

    public function isBlocked(): bool
    {
        return $this->blockedReason !== null;
    }

    /**
     * Quotes grouped by parcel, in parcel order.
     *
     * @return Collection<int, Collection<int, ShippingQuote>>
     */
    public function byParcel(): Collection
    {
        return $this->quotes
            ->groupBy(fn (ShippingQuote $q) => (int) data_get($q->line_allocation, 'parcel_index', 0))
            ->sortKeys();
    }

    /**
     * Cheapest combination: the cheapest service for each parcel.
     *
     * @return Collection<int, ShippingQuote>
     */
    public function cheapestPerParcel(): Collection
    {
        return $this->byParcel()->map(
            fn (Collection $parcelQuotes) => $parcelQuotes->sortBy(fn (ShippingQuote $q) => $q->getRawOriginal('amount_minor'))->first()
        )->values();
    }

    public function cheapestTotalMinor(): int
    {
        return (int) $this->cheapestPerParcel()->sum(fn (ShippingQuote $q) => (int) $q->getRawOriginal('amount_minor'));
    }

    public function isEmpty(): bool
    {
        return $this->quotes->isEmpty();
    }
}
