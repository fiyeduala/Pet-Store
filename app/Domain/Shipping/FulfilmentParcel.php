<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Models\ProductVariant;
use App\Models\Warehouse;

/**
 * One warehouse's share of an order. Each parcel is quoted, shipped and
 * tracked independently.
 */
final readonly class FulfilmentParcel
{
    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $lines
     */
    public function __construct(
        public Warehouse $warehouse,
        public array $lines,
    ) {}

    public function totalWeightGrams(): int
    {
        $total = 0;

        foreach ($this->lines as $line) {
            $total += $line['variant']->billableWeightGrams() * $line['quantity'];
        }

        return $total;
    }

    public function itemCount(): int
    {
        return array_sum(array_column($this->lines, 'quantity'));
    }

    /**
     * @return array<int, array{supplier_variant_id: string, quantity: int, weight_grams: int}>
     */
    public function toSupplierLines(): array
    {
        return array_values(array_map(fn (array $line) => [
            'supplier_variant_id' => (string) $line['variant']->supplier_variant_id,
            'quantity' => $line['quantity'],
            'weight_grams' => $line['variant']->billableWeightGrams(),
        ], $this->lines));
    }
}
