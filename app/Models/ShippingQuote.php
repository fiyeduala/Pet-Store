<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyMinor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingQuote extends Model
{
    public const UNIT_BUSINESS_DAYS = 'business_days';
    public const UNIT_DAYS = 'days';
    public const UNIT_UNKNOWN = 'unknown';

    public const TYPE_TRANSIT = 'transit';
    public const TYPE_PROCESSING = 'processing';
    public const TYPE_TOTAL = 'total';
    public const TYPE_UNKNOWN = 'unknown';

    public const SOURCE_SUPPLIER = 'supplier_api';
    public const SOURCE_OWNER_POLICY = 'owner_policy';
    public const SOURCE_FLAT = 'flat_rate';
    public const SOURCE_UNAVAILABLE = 'unavailable';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'raw_payload' => 'array',
            'line_allocation' => 'array',
            'quoted_at' => 'datetime',
            'expires_at' => 'datetime',
            'amount_minor' => MoneyMinor::class.':currency',
            'supplier_cost_minor' => MoneyMinor::class.':currency',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function hasEstimate(): bool
    {
        return $this->estimate_min !== null && $this->estimate_unit !== self::UNIT_UNKNOWN;
    }

    public function estimateLabel(): string
    {
        return app(\App\Domain\Shipping\DeliveryEstimatePresenter::class)->forQuote($this);
    }
}
