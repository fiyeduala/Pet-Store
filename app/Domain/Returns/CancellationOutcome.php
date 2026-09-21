<?php

declare(strict_types=1);

namespace App\Domain\Returns;

final readonly class CancellationOutcome
{
    public function __construct(
        public bool $cancelled,
        public string $message,
    ) {}
}
