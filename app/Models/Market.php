<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Market extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'currency', 'display_timezone', 'locale', 'is_enabled', 'is_default',
        'tax_mode', 'tax_provider', 'prices_include_tax', 'allow_overseas_fulfilment',
        'preferred_warehouse_countries', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'prices_include_tax' => 'boolean',
            'allow_overseas_fulfilment' => 'boolean',
            'preferred_warehouse_countries' => 'array',
        ];
    }

    public function shippingZones(): HasMany
    {
        return $this->hasMany(ShippingZone::class);
    }

    public function taxRules(): HasMany
    {
        return $this->hasMany(TaxRule::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public static function default(): self
    {
        return once(fn () => static::query()->where('is_default', true)->firstOrFail());
    }

    /**
     * Warehouse countries this market prefers to ship from, in priority order.
     *
     * @return array<int, string>
     */
    public function preferredCountries(): array
    {
        return $this->preferred_warehouse_countries ?: [$this->code];
    }
}
