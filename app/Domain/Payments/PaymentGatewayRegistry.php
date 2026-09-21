<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Payments\Contracts\PaymentGatewayAdapter;
use App\Domain\Payments\Exceptions\GatewayNotAvailable;
use App\Domain\Payments\Gateways\DemoGateway;
use App\Domain\Payments\Gateways\PayPalGateway;
use App\Domain\Payments\Gateways\PaystackGateway;
use App\Models\PaymentGateway;
use Illuminate\Support\Collection;
use RuntimeException;

class PaymentGatewayRegistry
{
    /** @var array<int, PaymentGatewayAdapter> */
    private array $resolved = [];

    public function for(PaymentGateway $gateway): PaymentGatewayAdapter
    {
        return $this->resolved[$gateway->id] ??= match ($gateway->code) {
            'paypal' => new PayPalGateway($gateway),
            'paystack' => new PaystackGateway($gateway),
            'demo' => new DemoGateway($gateway),
            default => throw new RuntimeException("No adapter is registered for gateway [{$gateway->code}]."),
        };
    }

    public function byCode(string $code): PaymentGatewayAdapter
    {
        $gateway = PaymentGateway::query()->where('code', $code)->first()
            ?? throw new GatewayNotAvailable("Payment method [{$code}] is not configured.");

        return $this->for($gateway);
    }

    /**
     * Methods that may legitimately be offered for this currency.
     *
     * A gateway that cannot accept the currency is left out entirely rather
     * than offered and then charged in something else.
     *
     * @return Collection<int, PaymentGateway>
     */
    public function availableFor(string $currency): Collection
    {
        return PaymentGateway::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->get()
            ->filter(fn (PaymentGateway $g) => $g->blockingReason($currency) === null)
            ->values();
    }

    /**
     * Why checkout cannot proceed at all, or null if at least one method works.
     */
    public function unavailableReason(string $currency): ?string
    {
        if ($this->availableFor($currency)->isNotEmpty()) {
            return null;
        }

        $configured = PaymentGateway::query()->get();

        if ($configured->isEmpty()) {
            return 'No payment method has been set up yet.';
        }

        $reasons = $configured
            ->map(fn (PaymentGateway $g) => $g->name.': '.$g->blockingReason($currency))
            ->implode('; ');

        return "No payment method can currently accept {$currency}. ({$reasons})";
    }

    public function flush(): void
    {
        $this->resolved = [];
    }
}
