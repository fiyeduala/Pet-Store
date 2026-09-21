<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = ['base_currency', 'quote_currency', 'rate', 'source', 'fetched_at'];

    protected function casts(): array
    {
        return ['fetched_at' => 'datetime'];
    }

    public static function latestFor(string $base, string $quote): ?self
    {
        return static::query()
            ->where('base_currency', strtoupper($base))
            ->where('quote_currency', strtoupper($quote))
            ->latest('fetched_at')
            ->first();
    }
}
