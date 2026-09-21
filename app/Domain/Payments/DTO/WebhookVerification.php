<?php

declare(strict_types=1);

namespace App\Domain\Payments\DTO;

use App\Support\Money\Money;

/**
 * The outcome of authenticating an inbound gateway notification.
 *
 * `verified` means the signature/authenticity check passed. It does NOT by
 * itself mean the event should be applied: the caller still has to match
 * the merchant account, order reference, amount and currency.
 */
final readonly class WebhookVerification
{
    public function __construct(
        public bool $verified,
        public ?string $eventId = null,
        public ?string $eventType = null,
        public ?string $providerReference = null,
        public ?string $orderReference = null,
        public ?Money $amount = null,
        public ?string $merchantId = null,
        public ?\DateTimeInterface $occurredAt = null,
        public ?string $error = null,
        public array $payload = [],
    ) {}

    public static function rejected(string $error, array $payload = []): self
    {
        return new self(false, error: $error, payload: $payload);
    }
}
