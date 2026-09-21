<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

use Illuminate\Support\Carbon;

final readonly class AuthResult
{
    public function __construct(
        public bool $ok,
        public ?string $message = null,
        public ?Carbon $expiresAt = null,
    ) {}
}
