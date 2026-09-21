<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[Test]
    public function it_holds_exact_minor_units(): void
    {
        $this->assertSame(1999, Money::ofMinor(1999, 'USD')->minor);
        $this->assertSame('19.99', Money::ofMinor(1999, 'USD')->toDecimalString());
    }

    #[Test]
    public function it_parses_decimal_strings_without_float_error(): void
    {
        // 0.1 + 0.2 in binary floating point is 0.30000000000000004.
        $a = Money::ofDecimalString('0.10', 'USD');
        $b = Money::ofDecimalString('0.20', 'USD');

        $this->assertSame(30, $a->plus($b)->minor);
        $this->assertSame('0.30', $a->plus($b)->toDecimalString());
    }

    #[Test]
    public function repeated_addition_does_not_drift(): void
    {
        $total = Money::zero('USD');

        for ($i = 0; $i < 1000; $i++) {
            $total = $total->plus(Money::ofDecimalString('0.07', 'USD'));
        }

        // A float accumulation of 0.07 a thousand times does not equal 70.
        $this->assertSame(7000, $total->minor);
    }

    #[Test]
    public function it_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to combine USD with NGN');

        Money::ofMinor(100, 'USD')->plus(Money::ofMinor(100, 'NGN'));
    }

    #[Test]
    public function it_rejects_a_malformed_currency_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::ofMinor(100, 'DOLLARS');
    }

    #[Test]
    public function basis_point_rates_are_exact(): void
    {
        // 7.25% of $100.00 is exactly $7.25.
        $this->assertSame(725, Money::ofMinor(10_000, 'USD')->percentageOfBasisPoints(725)->minor);

        // 8.875% of $19.99 rounds half up to $1.77, not $1.774...
        $this->assertSame(177, Money::ofMinor(1999, 'USD')->percentageOfBasisPoints(887)->minor);
    }

    #[Test]
    public function even_allocation_never_loses_or_invents_a_unit(): void
    {
        $parts = Money::ofMinor(1000, 'USD')->allocateEvenly(3);

        $this->assertSame([334, 333, 333], array_map(fn (Money $m) => $m->minor, $parts));
        $this->assertSame(1000, array_sum(array_map(fn (Money $m) => $m->minor, $parts)));
    }

    #[Test]
    public function weighted_allocation_preserves_the_exact_total(): void
    {
        // An awkward split that cannot divide evenly.
        $shares = Money::ofMinor(1000, 'USD')->allocateByWeights(['a' => 1, 'b' => 1, 'c' => 1]);

        $this->assertSame(1000, array_sum(array_map(fn (Money $m) => $m->minor, $shares)));

        $shares = Money::ofMinor(999, 'USD')->allocateByWeights(['a' => 333, 'b' => 666]);
        $this->assertSame(999, array_sum(array_map(fn (Money $m) => $m->minor, $shares)));
    }

    #[Test]
    public function allocation_handles_a_negative_total(): void
    {
        // Refund allocations are negative and must also sum back exactly.
        $parts = Money::ofMinor(-1000, 'USD')->allocateEvenly(3);

        $this->assertSame(-1000, array_sum(array_map(fn (Money $m) => $m->minor, $parts)));
    }

    #[Test]
    public function comparisons_respect_currency(): void
    {
        $this->assertTrue(Money::ofMinor(200, 'USD')->greaterThan(Money::ofMinor(100, 'USD')));

        $this->expectException(InvalidArgumentException::class);
        Money::ofMinor(200, 'USD')->greaterThan(Money::ofMinor(100, 'EUR'));
    }
}
