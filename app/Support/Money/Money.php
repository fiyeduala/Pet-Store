<?php

declare(strict_types=1);

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact monetary amount.
 *
 * Amounts are held as integer minor units with an explicit ISO 4217 currency.
 * Every operation delegates to brick/math's arbitrary precision decimals, so
 * no binary floating point arithmetic is ever performed on a financial value.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    public static function ofMinor(int $minor, string $currency): self
    {
        return new self($minor, self::normaliseCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, self::normaliseCurrency($currency));
    }

    /**
     * Build from a decimal string such as "12.99".
     *
     * A string (never a float) must be supplied so the caller cannot lose
     * precision before the value reaches us.
     */
    public static function ofDecimalString(string $amount, string $currency): self
    {
        $currency = self::normaliseCurrency($currency);

        return new self(
            BrickMoney::of(BigDecimal::of($amount), $currency, roundingMode: RoundingMode::HALF_UP)
                ->getMinorAmount()
                ->toInt(),
            $currency,
        );
    }

    public static function ofNullableMinor(?int $minor, string $currency): ?self
    {
        return $minor === null ? null : self::ofMinor($minor, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->minor * $factor, $this->currency);
    }

    /**
     * Multiply by an exact decimal ratio, e.g. "1.35" for a 35% markup.
     */
    public function multipliedByRatio(BigDecimal|string|int $ratio, RoundingMode $rounding = RoundingMode::HALF_UP): self
    {
        $result = BigDecimal::of($this->minor)
            ->multipliedBy(BigDecimal::of($ratio))
            ->toScale(0, $rounding)
            ->toInt();

        return new self($result, $this->currency);
    }

    /**
     * Apply a rate expressed in basis points (725 = 7.25%) and return the
     * resulting portion, not the grossed-up total.
     */
    public function percentageOfBasisPoints(int $basisPoints, RoundingMode $rounding = RoundingMode::HALF_UP): self
    {
        $result = BigDecimal::of($this->minor)
            ->multipliedBy($basisPoints)
            ->dividedBy(10_000, 0, $rounding)
            ->toInt();

        return new self($result, $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->minor), $this->currency);
    }

    /**
     * Split across N parts without losing or inventing a single minor unit.
     * Remainder units are distributed to the earliest parts.
     *
     * @return array<int, self>
     */
    public function allocateEvenly(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Cannot allocate across fewer than one part.');
        }

        $base = intdiv($this->minor, $parts);
        $remainder = $this->minor - ($base * $parts);
        $out = [];

        for ($i = 0; $i < $parts; $i++) {
            $extra = $i < abs($remainder) ? ($remainder <=> 0) : 0;
            $out[] = new self($base + $extra, $this->currency);
        }

        return $out;
    }

    /**
     * Split proportionally to integer weights, preserving the exact total.
     *
     * @param  array<int|string, int>  $weights
     * @return array<int|string, self>
     */
    public function allocateByWeights(array $weights): array
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            throw new InvalidArgumentException('Allocation weights must sum to a positive number.');
        }

        $allocated = [];
        $running = 0;

        foreach ($weights as $key => $weight) {
            $share = intdiv($this->minor * $weight, $total);
            $allocated[$key] = $share;
            $running += $share;
        }

        // Hand the rounding remainder to the heaviest weights, largest first.
        $remainder = $this->minor - $running;
        if ($remainder !== 0) {
            $order = $weights;
            arsort($order);
            $step = $remainder <=> 0;

            foreach (array_keys($order) as $key) {
                if ($remainder === 0) {
                    break;
                }
                $allocated[$key] += $step;
                $remainder -= $step;
            }
        }

        return array_map(fn (int $minor): self => new self($minor, $this->currency), $allocated);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor >= $other->minor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    public static function min(self $a, self $b): self
    {
        return $a->lessThan($b) ? $a : $b;
    }

    public static function max(self $a, self $b): self
    {
        return $a->greaterThan($b) ? $a : $b;
    }

    /**
     * @param  iterable<self>  $items
     */
    public static function sum(iterable $items, string $currency): self
    {
        $total = self::zero($currency);

        foreach ($items as $item) {
            $total = $total->plus($item);
        }

        return $total;
    }

    public function toDecimalString(): string
    {
        return (string) $this->toBrick()->getAmount();
    }

    public function toBrick(): BrickMoney
    {
        return BrickMoney::ofMinor($this->minor, $this->currency);
    }

    public function format(?string $locale = null): string
    {
        return $this->toBrick()->formatTo($locale ?? config('petstore.display_locale', 'en_US'));
    }

    public function __toString(): string
    {
        return $this->currency.' '.$this->toDecimalString();
    }

    /**
     * @return array{minor: int, currency: string, decimal: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'minor' => $this->minor,
            'currency' => $this->currency,
            'decimal' => $this->toDecimalString(),
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Refusing to combine {$this->currency} with {$other->currency}. Convert explicitly first."
            );
        }
    }

    private static function normaliseCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Invalid ISO 4217 currency code: {$currency}");
        }

        return $currency;
    }
}
