<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierSyncLog extends Model
{
    protected $fillable = [
        'supplier_id', 'type', 'status', 'mode', 'reference', 'items_processed',
        'items_failed', 'context', 'error', 'retry_of_id', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    public function durationSeconds(): ?float
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return round($this->finished_at->floatDiffInSeconds($this->started_at), 2);
    }

    public function isRetryable(): bool
    {
        return in_array($this->status, ['failed', 'partial'], true);
    }
}
