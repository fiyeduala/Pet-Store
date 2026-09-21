<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyMinor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierOffer extends Model
{
    protected $fillable = [
        'supplier_id', 'product_variant_id', 'supplier_variant_id',
        'cost_minor', 'currency', 'is_available', 'source_payload', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'source_payload' => 'array',
            'synced_at' => 'datetime',
            'cost_minor' => MoneyMinor::class.':currency',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
