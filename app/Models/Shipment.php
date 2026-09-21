<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'raw_payload' => 'array',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'tracking_checked_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fulfilment(): BelongsTo
    {
        return $this->belongsTo(Fulfilment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /**
     * Delivery is only claimed when the carrier actually evidenced it.
     */
    public function isEvidencedDelivered(): bool
    {
        return $this->state === 'delivered'
            && $this->delivered_at !== null
            && filled($this->delivery_evidence);
    }

    public function estimateLabel(): string
    {
        return app(\App\Domain\Shipping\DeliveryEstimatePresenter::class)->forShipment($this);
    }
}
