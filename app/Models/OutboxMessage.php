<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable record of a side effect that must happen exactly once. Written in
 * the same transaction as the state change that requires it, then drained by
 * a worker, so a crash in between cannot lose the side effect.
 */
class OutboxMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function scopePending($query)
    {
        return $query->whereNull('processed_at')->where('available_at', '<=', now());
    }
}
