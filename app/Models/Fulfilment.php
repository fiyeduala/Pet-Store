<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyMinor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Fulfilment extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_SUBMITTING = 'submitting';
    public const STATE_SUBMITTED = 'submitted';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_REJECTED = 'rejected';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_NEEDS_RECONCILIATION = 'needs_reconciliation';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'merchandise_cost_minor' => MoneyMinor::class.':currency',
            'shipping_cost_minor' => MoneyMinor::class.':currency',
            'packaging_cost_minor' => MoneyMinor::class.':currency',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FulfilmentItem::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function supplierPayment(): HasOne
    {
        return $this->hasOne(SupplierPayment::class);
    }

    public function packagingRecord(): BelongsTo
    {
        return $this->belongsTo(PackagingRecord::class);
    }

    public function isLive(): bool
    {
        return $this->mode === Supplier::MODE_LIVE;
    }

    /**
     * True when we sent a request but never got a usable answer. The order
     * must be reconciled by reference before any retry is attempted.
     */
    public function needsReconciliation(): bool
    {
        return $this->state === self::STATE_NEEDS_RECONCILIATION;
    }

    public function totalCostMinor(): int
    {
        return (int) ($this->getRawOriginal('merchandise_cost_minor') ?? 0)
            + (int) ($this->getRawOriginal('shipping_cost_minor') ?? 0)
            + (int) ($this->getRawOriginal('packaging_cost_minor') ?? 0);
    }
}
