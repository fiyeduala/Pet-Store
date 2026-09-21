<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStock extends Model
{
    protected $fillable = [
        'warehouse_id', 'product_variant_id', 'supplier_variant_id',
        'quantity', 'quantity_known', 'synced_at', 'stale_after', 'source_payload',
    ];

    protected function casts(): array
    {
        return [
            'quantity_known' => 'boolean',
            'synced_at' => 'datetime',
            'stale_after' => 'datetime',
            'source_payload' => 'array',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * A stale reading is treated as unknown, not as the last number we saw.
     */
    public function isStale(): bool
    {
        return $this->stale_after !== null && $this->stale_after->isPast();
    }

    public function freshnessLabel(): string
    {
        if (! $this->quantity_known) {
            return 'Not reported';
        }

        if ($this->isStale()) {
            return 'Stale since '.$this->stale_after->diffForHumans();
        }

        return 'Updated '.$this->synced_at?->diffForHumans();
    }
}
