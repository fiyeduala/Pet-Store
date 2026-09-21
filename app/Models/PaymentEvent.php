<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per inbound provider event. The unique (gateway_code, event_id)
 * constraint is what makes duplicate delivery a no-op, and `occurred_at`
 * is what makes out-of-order delivery detectable.
 */
class PaymentEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signature_verified' => 'boolean',
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
