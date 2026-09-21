<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts an integer minor-unit column to a {@see Money}.
 *
 * The currency is read from a sibling column so an amount can never be
 * interpreted in the wrong currency. Usage:
 *
 *   'total_minor' => MoneyMinor::class.':currency'
 *
 * @implements CastsAttributes<Money|null, Money|int|null>
 */
class MoneyMinor implements CastsAttributes
{
    public function __construct(
        protected string $currencyColumn = 'currency',
        protected ?string $fallbackCurrency = null,
    ) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::ofMinor((int) $value, $this->resolveCurrency($attributes));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (is_int($value)) {
            return [$key => $value];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(
                sprintf('%s must be set to a %s instance, an int of minor units, or null.', $key, Money::class)
            );
        }

        $expected = $this->resolveCurrency($attributes);

        if ($value->currency !== $expected) {
            throw new InvalidArgumentException(
                sprintf(
                    'Refusing to store %s into %s.%s which is denominated in %s.',
                    $value->currency,
                    $model->getTable(),
                    $key,
                    $expected
                )
            );
        }

        return [$key => $value->minor];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function resolveCurrency(array $attributes): string
    {
        $currency = $attributes[$this->currencyColumn] ?? $this->fallbackCurrency;

        if (! is_string($currency) || $currency === '') {
            throw new InvalidArgumentException(
                "Cannot resolve currency from column [{$this->currencyColumn}]; it is not set on this record."
            );
        }

        return strtoupper($currency);
    }
}
