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

    /**
     * The marker is matched anywhere in the reference, not only at the end,
     * because fulfilment appends a parcel suffix such as "-P1". An order
     * numbered PS-260921-ABCDE-TIMEOUT therefore still triggers the timeout
     * scenario on reference PS-260921-ABCDE-TIMEOUT-P1.
     */
    public static function forReference(string $reference): self
    {
        $reference = strtoupper($reference);

        return match (true) {
            str_contains($reference, '-TIMEOUT') => self::TIMEOUT,
            str_contains($reference, '-OOS') => self::OUT_OF_STOCK,
            str_contains($reference, '-NOBAL') => self::INSUFFICIENT_BALANCE,
            default => self::HAPPY_PATH,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::HAPPY_PATH => 'Succeeds normally',
            self::TIMEOUT => 'Times out with an unknown outcome (order number contains -TIMEOUT)',
            self::OUT_OF_STOCK => 'Rejected: out of stock (order number contains -OOS)',
            self::INSUFFICIENT_BALANCE => 'Supplier payment fails: insufficient balance (order number contains -NOBAL)',
        };
    }
}
