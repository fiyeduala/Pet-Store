<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStateEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id', 'machine', 'from_state', 'to_state',
        'actor_type', 'actor_id', 'reason', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function describe(): string
    {
        $machine = str_replace('_', ' ', $this->machine);

        return ucfirst($machine).': '.($this->from_state ?? 'n/a').' → '.$this->to_state;
    }
}
