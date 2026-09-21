<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Exceptions;

/**
 * The supplier's API cannot perform this operation for this account.
 *
 * Callers must surface an honest manual workflow instead of pretending the
 * operation succeeded.
 */
class SupplierOperationUnsupported extends SupplierException
{
    public function __construct(
        public readonly string $operation,
        string $message = '',
        public readonly ?string $manualWorkaround = null,
    ) {
        parent::__construct($message !== '' ? $message : "The supplier API does not support [{$operation}] for this account.");
    }
}
