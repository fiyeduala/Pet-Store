<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Adapters\Demo;

/**
 * Failure scenarios the demo adapter can reproduce on demand.
 *
 * Selected by a suffix on the internal order reference so a tester (or a
 * test) can force a specific path without changing any configuration.
 */
enum DemoScenario: string
{
    case HAPPY_PATH = 'happy_path';
    case TIMEOUT = 'timeout';
    case OUT_OF_STOCK = 'out_of_stock';
    case INSUFFICIENT_BALANCE = 'insufficient_balance';

    public static function forReference(string $reference): self
    {
        $reference = strtoupper($reference);

        return match (true) {
            str_ends_with($reference, '-TIMEOUT') => self::TIMEOUT,
            str_ends_with($reference, '-OOS') => self::OUT_OF_STOCK,
            str_ends_with($reference, '-NOBAL') => self::INSUFFICIENT_BALANCE,
            default => self::HAPPY_PATH,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::HAPPY_PATH => 'Succeeds normally',
            self::TIMEOUT => 'Times out with an unknown outcome (reference ends -TIMEOUT)',
            self::OUT_OF_STOCK => 'Rejected: out of stock (reference ends -OOS)',
            self::INSUFFICIENT_BALANCE => 'Supplier payment fails: insufficient balance (reference ends -NOBAL)',
        };
    }
}
