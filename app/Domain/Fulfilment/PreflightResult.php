<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment;

final readonly class PreflightResult
{
    /**
     * @param  array<int, array{type: string, detail: string}>  $findings
     */
    public function __construct(
        public bool $passed,
        public array $findings = [],
        public ?string $serviceCode = null,
    ) {}

    public function summary(): string
    {
        if ($this->findings === []) {
            return 'All pre-submission checks passed.';
        }

        return implode(' ', array_column($this->findings, 'detail'));
    }

    /**
     * The most serious finding type, used to categorise the exception.
     */
    public function exceptionType(): string
    {
        $priority = ['stock_changed', 'service_unavailable', 'cost_changed', 'stock_unknown', 'stock_unreadable'];

        foreach ($priority as $type) {
            if (in_array($type, array_column($this->findings, 'type'), true)) {
                return $type;
            }
        }

        return 'stock_changed';
    }
}
