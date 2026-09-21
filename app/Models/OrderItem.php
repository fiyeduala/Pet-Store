<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyMinor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'unit_price_minor' => MoneyMinor::class.':currency',
            'line_subtotal_minor' => MoneyMinor::class.':currency',
            'line_discount_minor' => MoneyMinor::class.':currency',
            'line_tax_minor' => MoneyMinor::class.':currency',
            'supplier_cost_minor' => MoneyMinor::class.':supplier_cost_currency',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function quantityOutstanding(): int
    {
        return max(0, $this->quantity - $this->quantity_fulfilled - $this->quantity_refunded);
    }

    public function quantityRefundable(): int
    {
        return max(0, $this->quantity - $this->quantity_refunded);
    }

    public function lineTotalMinor(): int
    {
        return (int) $this->getRawOriginal('line_subtotal_minor')
            - (int) $this->getRawOriginal('line_discount_minor')
            + (int) $this->getRawOriginal('line_tax_minor');
    }
}
