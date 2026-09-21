<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyMinor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_REQUIRES_ACTION = 'requires_action';
    public const STATUS_AUTHORISED = 'authorised';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'currency_disclosure_accepted' => 'boolean',
            'raw_payload' => 'array',
            'authorised_at' => 'datetime',
            'captured_at' => 'datetime',
            'fx_rate_at' => 'datetime',
            'amount_minor' => MoneyMinor::class.':currency',
            'fee_minor' => MoneyMinor::class.':currency',
            'refunded_minor' => MoneyMinor::class.':currency',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function isCaptured(): bool
    {
        return in_array($this->status, [self::STATUS_CAPTURED, self::STATUS_PARTIALLY_REFUNDED], true);
    }

    public function refundableMinor(): int
    {
        return max(0, (int) $this->getRawOriginal('amount_minor') - (int) $this->getRawOriginal('refunded_minor'));
    }

    /** True when the customer was charged in a currency other than the order's. */
    public function wasConverted(): bool
    {
        return $this->presented_currency !== null && $this->presented_currency !== $this->currency;
    }
}
