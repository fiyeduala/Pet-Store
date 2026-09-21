<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = [
        'supplier_id', 'code', 'name', 'country_code', 'state_code',
        'city', 'is_enabled', 'priority', 'notes',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeInCountries($query, array $countryCodes)
    {
        return $query->whereIn('country_code', array_map('strtoupper', $countryCodes));
    }

    public function isDomesticFor(Market $market): bool
    {
        return in_array(strtoupper($this->country_code), array_map('strtoupper', $market->preferredCountries()), true);
    }

    public function label(): string
    {
        return trim("{$this->name} ({$this->country_code}".($this->state_code ? "/{$this->state_code}" : '').')');
    }
}
