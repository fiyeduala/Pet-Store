<?php

declare(strict_types=1);

namespace App\Domain\Checkout;

use App\Domain\Tax\TaxResult;
use App\Support\Money\Money;

final readonly class CartTotals
{
    public function __construct(
        public Money $subtotal,
        public Money $discount,
        public Money $shipping,
        public Money $tax,
        public Money $total,
        public ?TaxResult $taxResult = null,
        public ?string $shippingNote = null,
        public bool $shippingEstimated = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->total->currency,
            'subtotal_minor' => $this->subtotal->minor,
            'discount_minor' => $this->discount->minor,
            'shipping_minor' => $this->shipping->minor,
            'tax_minor' => $this->tax->minor,
            'total_minor' => $this->total->minor,
            'tax' => $this->taxResult?->toArray(),
            'shipping_note' => $this->shippingNote,
            'shipping_estimated' => $this->shippingEstimated,
        ];
    }
}
