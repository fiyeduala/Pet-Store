<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Exceptions;

class SupplierRateLimited extends SupplierException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 1)
    {
        parent::__construct($message);
    }
}
