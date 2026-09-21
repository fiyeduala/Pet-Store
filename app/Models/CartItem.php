<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyMinor;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'unit_price_minor' => MoneyMinor::class.':currency',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function lineTotal(): Money
    {
        return Money::ofMinor((int) $this->getRawOriginal('unit_price_minor') * $this->quantity, $this->currency);
    }
}
