<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Heartbeat extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_run_at' => 'datetime', 'metadata' => 'array'];
    }

    public static function beat(string $key, string $status = 'ok', ?string $message = null, array $metadata = []): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            [
                'last_run_at' => now(),
                'last_status' => $status,
                'last_message' => $message,
                'metadata' => $metadata,
            ]
        );
    }

    public function isHealthy(): bool
    {
        if ($this->last_run_at === null) {
            return false;
        }

        // Allow two missed intervals before calling it unhealthy.
        return $this->last_run_at->gt(now()->subMinutes($this->expected_interval_minutes * 2))
            && $this->last_status !== 'failed';
    }

    public function statusLabel(): string
    {
        if ($this->last_run_at === null) {
            return 'Never run';
        }

        return $this->isHealthy()
            ? 'Healthy — last run '.$this->last_run_at->diffForHumans()
            : 'Stale — last run '.$this->last_run_at->diffForHumans();
    }
}
