<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Exceptions;

use Throwable;

class SupplierRequestFailed extends SupplierException
{
    public function __construct(
        string $message,
        public readonly ?string $endpoint = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $providerCode = null,
        /** True when we cannot tell whether the supplier applied the request. */
        public readonly bool $outcomeUnknown = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
