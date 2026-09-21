<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

use Illuminate\Support\Carbon;

final readonly class TrackingResult
{
    /**
     * @param  array<int, array{at: string, status: string, location?: string}>  $events
     */
    public function __construct(
        public string $trackingNumber,
        public string $state,
        public ?string $carrier = null,
        public ?Carbon $deliveredAt = null,
        public ?string $deliveryEvidence = null,
        public array $events = [],
        public array $raw = [],
    ) {}
}
