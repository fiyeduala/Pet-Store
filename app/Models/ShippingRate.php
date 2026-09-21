<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyMinor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRate extends Model
{
    public const MODE_QUOTED = 'quoted';
    public const MODE_FLAT = 'flat';
    public const MODE_FREE_THRESHOLD = 'free_threshold';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'flat_amount_minor' => MoneyMinor::class.':currency',
            'free_threshold_minor' => MoneyMinor::class.':currency',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    public function hasOwnerPolicyEstimate(): bool
    {
        return $this->policy_min_days !== null && $this->policy_day_unit !== null;
    }
}
