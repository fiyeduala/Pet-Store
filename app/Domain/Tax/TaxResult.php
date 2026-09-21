<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Support\Money\Money;

final readonly class TaxResult
{
    /**
     * @param  array<int, array{name: string, rate: string, amount_minor: int}>  $lines
     */
    public function __construct(
        public Money $amount,
        public array $lines = [],
        public string $basis = 'none',
        /** True when no rule matched AND none was configured to match. */
        public bool $unconfigured = false,
        public ?string $note = null,
    ) {}

    public static function none(string $currency, ?string $note = null, bool $unconfigured = false): self
    {
        return new self(Money::zero($currency), [], 'none', $unconfigured, $note);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount_minor' => $this->amount->minor,
            'currency' => $this->amount->currency,
            'basis' => $this->basis,
            'lines' => $this->lines,
            'unconfigured' => $this->unconfigured,
            'note' => $this->note,
        ];
    }
}
