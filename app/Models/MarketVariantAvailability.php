<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketVariantAvailability extends Model
{
    protected $table = 'market_variant_availability';

    protected $fillable = ['market_id', 'product_variant_id', 'is_available', 'price_override_minor', 'reason'];

    protected function casts(): array
    {
        return ['is_available' => 'boolean'];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
