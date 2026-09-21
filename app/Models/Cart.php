<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(ShippingQuote::class, 'quote_group', 'selected_quote_group');
    }

    public function itemCount(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    public function subtotal(): Money
    {
        return Money::ofMinor(
            (int) $this->items->sum(fn (CartItem $i) => (int) $i->getRawOriginal('unit_price_minor') * $i->quantity),
            $this->currency
        );
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }
}
