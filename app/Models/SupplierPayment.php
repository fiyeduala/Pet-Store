<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyMinor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money paid by the store to the supplier. Entirely separate from the money
 * a customer paid the store: different rails, different authorisation.
 */
class SupplierPayment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_AUTHORISED = 'authorised';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_INSUFFICIENT = 'insufficient_balance';
    public const STATUS_REFUNDED = 'refunded';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'raw_payload' => 'array',
            'authorised_at' => 'datetime',
            'paid_at' => 'datetime',
            'amount_minor' => MoneyMinor::class.':currency',
        ];
    }

    public function fulfilment(): BelongsTo
    {
        return $this->belongsTo(Fulfilment::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function authoriser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
