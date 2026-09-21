<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The admin exception queue. Anything that needs a human decision lands
 * here rather than being silently retried or silently dropped.
 */
class OperationalException extends Model
{
    public const STATE_OPEN = 'open';
    public const STATE_ACKNOWLEDGED = 'acknowledged';
    public const STATE_RESOLVED = 'resolved';
    public const STATE_DISMISSED = 'dismissed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'resolved_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fulfilment(): BelongsTo
    {
        return $this->belongsTo(Fulfilment::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('state', [self::STATE_OPEN, self::STATE_ACKNOWLEDGED]);
    }
}
