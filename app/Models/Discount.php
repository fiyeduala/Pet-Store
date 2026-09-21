<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyMinor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discount extends Model
{
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';
    public const TYPE_FREE_SHIPPING = 'free_shipping';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'applies_to' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'amount_minor' => MoneyMinor::class.':currency',
            'min_subtotal_minor' => MoneyMinor::class.':currency',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    public function isCurrentlyValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $this->starts_at->isAfter($now)) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->isBefore($now)) {
            return false;
        }

        return ! ($this->usage_limit !== null && $this->used_count >= $this->usage_limit);
    }

    public function reachedLimitFor(?string $email): bool
    {
        if ($this->per_customer_limit === null || blank($email)) {
            return false;
        }

        return $this->redemptions()->where('email', $email)->count() >= $this->per_customer_limit;
    }
}
