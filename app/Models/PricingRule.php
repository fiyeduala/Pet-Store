<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_MARKET = 'market';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_PRODUCT = 'product';

    public const STRATEGY_FIXED_MARKUP = 'fixed_markup';
    public const STRATEGY_PERCENTAGE_MARKUP = 'percentage_markup';
    public const STRATEGY_TARGET_MARGIN = 'target_margin';

    /** Most specific scope wins. Higher number = more specific. */
    public const SCOPE_SPECIFICITY = [
        self::SCOPE_GLOBAL => 0,
        self::SCOPE_MARKET => 1,
        self::SCOPE_CATEGORY => 2,
        self::SCOPE_PRODUCT => 3,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function specificity(): int
    {
        return self::SCOPE_SPECIFICITY[$this->scope] ?? 0;
    }

    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        return ! ($this->starts_at && $this->starts_at->isAfter($now))
            && ! ($this->ends_at && $this->ends_at->isBefore($now));
    }

    public function describe(): string
    {
        return match ($this->strategy) {
            self::STRATEGY_FIXED_MARKUP => 'Cost + fixed '.number_format(($this->markup_amount_minor ?? 0) / 100, 2),
            self::STRATEGY_PERCENTAGE_MARKUP => 'Cost + '.rtrim(rtrim((string) $this->markup_percentage, '0'), '.').'% markup',
            self::STRATEGY_TARGET_MARGIN => rtrim(rtrim((string) $this->target_margin_percentage, '0'), '.').'% target margin on retail',
            default => $this->strategy,
        };
    }
}
