<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'codes' => 'array',
            'applies_to_shipping' => 'boolean',
            'is_active' => 'boolean',
            'configured_at' => 'datetime',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function matches(string $countryCode, ?string $state, ?string $postalCode): bool
    {
        $codes = array_map('strtoupper', $this->codes ?? []);

        return match ($this->match_type) {
            'country' => in_array(strtoupper($countryCode), $codes, true),
            'state' => $state !== null && in_array(strtoupper($state), $codes, true),
            'zip_prefix' => $postalCode !== null && $this->zipMatches($postalCode, $codes),
            default => false,
        };
    }

    public function ratePercentLabel(): string
    {
        return rtrim(rtrim(number_format($this->rate_basis_points / 100, 2), '0'), '.').'%';
    }

    private function zipMatches(string $postalCode, array $prefixes): bool
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
