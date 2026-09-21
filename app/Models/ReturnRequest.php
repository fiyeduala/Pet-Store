<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnRequest extends Model
{
    public const STATE_REQUESTED = 'requested';
    public const STATE_APPROVED = 'approved';
    public const STATE_REJECTED = 'rejected';
    public const STATE_AWAITING_RETURN = 'awaiting_return';
    public const STATE_RECEIVED = 'received';
    public const STATE_REFUNDED = 'refunded';
    public const STATE_CLOSED = 'closed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }
}
