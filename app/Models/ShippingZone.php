<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingZone extends Model
{
    public const MATCH_COUNTRY = 'country';
    public const MATCH_STATE = 'state';
    public const MATCH_ZIP_PREFIX = 'zip_prefix';
    public const MATCH_REST = 'rest';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'codes' => 'array',
            'is_excluded' => 'boolean',
            'blocks_po_boxes' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class)->where('is_active', true)->orderBy('position');
    }

    /**
     * Does this zone cover the given destination?
     */
    public function matches(string $countryCode, ?string $state, ?string $postalCode): bool
    {
        $codes = array_map('strtoupper', $this->codes ?? []);

        return match ($this->match_type) {
            self::MATCH_COUNTRY => in_array(strtoupper($countryCode), $codes, true),
            self::MATCH_STATE => $state !== null && in_array(strtoupper($state), $codes, true),
            self::MATCH_ZIP_PREFIX => $postalCode !== null && $this->postalMatches($postalCode, $codes),
            self::MATCH_REST => true,
            default => false,
        };
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    private function postalMatches(string $postalCode, array $prefixes): bool
    {
        $normalised = strtoupper(preg_replace('/\s+/', '', $postalCode) ?? '');

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($normalised, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
