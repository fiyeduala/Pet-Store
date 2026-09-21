<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment;

use App\Models\Fulfilment;

final readonly class SubmissionOutcome
{
    private function __construct(
        public string $status,
        public string $message,
        public ?Fulfilment $fulfilment = null,
    ) {}

    public static function submitted(Fulfilment $f): self
    {
        return new self('submitted', 'Order submitted and confirmed by the supplier.', $f);
    }

    public static function alreadyDone(Fulfilment $f): self
    {
        return new self('already_done', 'This fulfilment has already been sent to the supplier.', $f);
    }

    public static function reconciledFound(Fulfilment $f): self
    {
        return new self('reconciled_found', 'Reconciliation found an existing supplier order; no duplicate was created.', $f);
    }

    public static function reconciledAbsent(): self
    {
        return new self('reconciled_absent', 'Reconciliation confirmed no supplier order exists. It is safe to submit.');
    }

    public static function needsReconciliation(string $message): self
    {
        return new self('needs_reconciliation', $message);
    }

    public static function rejected(string $message): self
    {
        return new self('rejected', $message);
    }

    public static function refused(string $message): self
    {
        return new self('refused', $message);
    }

    public static function held(string $message): self
    {
        return new self('held', $message);
    }

    public function isSuccess(): bool
    {
        return in_array($this->status, ['submitted', 'already_done', 'reconciled_found'], true);
    }

    public function requiresAttention(): bool
    {
        return in_array($this->status, ['needs_reconciliation', 'rejected', 'held'], true);
    }
}
