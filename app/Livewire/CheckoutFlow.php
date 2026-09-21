<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Checkout\CartService;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Checkout\QuoteExpiredException;
use App\Domain\Payments\Exceptions\GatewayNotAvailable;
use App\Domain\Payments\PaymentGatewayRegistry;
use App\Domain\Payments\PaymentService;
use App\Domain\Shipping\ShippingQuoteService;
use App\Domain\Shipping\ShippingQuoteSet;
use App\Domain\Shipping\WarehouseSelector;
use App\Livewire\Concerns\InteractsWithCart;
use App\Models\PaymentGateway;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Single-page checkout: contact, address, delivery, payment, review.
 *
 * Shipping is re-quoted whenever the address changes, and the quote is
 * re-validated at submission. A shopper is never charged against a stale
 * quote, and is never shown a delivery promise we cannot evidence.
 */
class CheckoutFlow extends Component
{
    use InteractsWithCart;

    public string $step = 'details';

    #[Validate('required|email:rfc|max:190')]
    public string $email = '';

    #[Validate('required|string|max:120')]
    public string $firstName = '';

    #[Validate('required|string|max:120')]
    public string $lastName = '';

    #[Validate('required|string|max:40')]
    public string $phone = '';

    #[Validate('required|string|max:190')]
    public string $line1 = '';

    #[Validate('nullable|string|max:190')]
    public string $line2 = '';

    #[Validate('required|string|max:120')]
    public string $city = '';

    #[Validate('required|string|max:64')]
    public string $state = '';

    #[Validate('required|string|max:12')]
    public string $postalCode = '';

    public string $countryCode = 'US';

    #[Validate('nullable|string|max:500')]
    public string $note = '';

    public string $discountCode = '';

    public ?string $discountMessage = null;

    /** Selected quote id per parcel index. @var array<int, int> */
    public array $selectedQuotes = [];

    public ?string $selectedGateway = null;

    public bool $createAccount = false;

    public ?string $checkoutError = null;

    public bool $placing = false;

    private ?ShippingQuoteSet $quoteSet = null;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user !== null) {
            $this->email = $user->email;
            $this->phone = (string) $user->phone;

            if ($address = $user->defaultShippingAddress()) {
                $this->firstName = $address->first_name;
                $this->lastName = $address->last_name;
                $this->line1 = $address->line1;
                $this->line2 = (string) $address->line2;
                $this->city = $address->city;
                $this->state = (string) $address->state;
                $this->postalCode = $address->postal_code;
            }
        }
    }

    public function updated(string $property): void
    {
        // Any address change invalidates the previous delivery quote.
        if (in_array($property, ['line1', 'line2', 'city', 'state', 'postalCode', 'countryCode'], true)) {
            $this->selectedQuotes = [];
            $this->quoteSet = null;
        }
    }

    public function applyDiscount(): void
    {
        $result = app(CartService::class)->applyDiscount($this->currentCart(), $this->discountCode, $this->email ?: null);
        $this->discountMessage = $result['message'];
    }

    public function removeDiscount(): void
    {
        app(CartService::class)->removeDiscount($this->currentCart());
        $this->discountCode = '';
        $this->discountMessage = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function destination(): ?array
    {
        if ($this->postalCode === '' || $this->line1 === '' || $this->city === '') {
            return null;
        }

        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'line1' => $this->line1,
            'line2' => $this->line2 ?: null,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'phone' => $this->phone,
        ];
    }

    public function quotes(): ?ShippingQuoteSet
    {
        if ($this->quoteSet !== null) {
            return $this->quoteSet;
        }

        $destination = $this->destination();

        if ($destination === null) {
            return null;
        }

        $cart = $this->currentCart();

        if ($cart->isEmpty()) {
            return null;
        }

        $market = $this->currentMarket();
        $carts = app(CartService::class);

        $plan = app(WarehouseSelector::class)->plan(
            $cart->items->map(fn ($i) => ['variant' => $i->variant, 'quantity' => $i->quantity]),
            $market,
            allowOverseas: $market->allow_overseas_fulfilment,
        );

        $this->quoteSet = app(ShippingQuoteService::class)->quote(
            $plan,
            $market,
            $destination,
            $cart->subtotal(),
            $carts->discountAmount($cart),
        );

        // Default to the cheapest option per parcel so the shopper always
        // has a valid selection.
        if ($this->selectedQuotes === [] && ! $this->quoteSet->isBlocked()) {
            foreach ($this->quoteSet->byParcel() as $index => $parcelQuotes) {
                $cheapest = $parcelQuotes->sortBy(fn ($q) => (int) $q->getRawOriginal('amount_minor'))->first();
                $this->selectedQuotes[$index] = $cheapest->id;
            }
        }

        return $this->quoteSet;
    }

    public function selectQuote(int $parcelIndex, int $quoteId): void
    {
        $this->selectedQuotes[$parcelIndex] = $quoteId;
    }

    public function placeOrder(): mixed
    {
        $this->checkoutError = null;
        $this->validate();

        $cart = $this->currentCart();

        if ($cart->isEmpty()) {
            $this->checkoutError = 'Your basket is empty.';

            return null;
        }

        if ($this->guestCheckoutDisabled()) {
            $this->checkoutError = 'Please sign in or create an account to complete your order.';

            return null;
        }

        if ($this->selectedQuotes === []) {
            $this->checkoutError = 'Choose a delivery service.';

            return null;
        }

        $gateway = PaymentGateway::query()->where('code', $this->selectedGateway)->first();

        if ($gateway === null) {
            $this->checkoutError = 'Choose a payment method.';

            return null;
        }

        $blocking = $gateway->blockingReason($cart->currency);

        if ($blocking !== null) {
            // Never silently convert or substitute. Say why and stop.
            $this->checkoutError = "{$gateway->name} cannot take this payment: {$blocking}";

            return null;
        }

        try {
            $order = app(CheckoutService::class)->place(
                $cart,
                ['email' => $this->email, 'phone' => $this->phone, 'note' => $this->note ?: null],
                $this->destination(),
                array_values($this->selectedQuotes),
            );
        } catch (QuoteExpiredException $e) {
            $this->quoteSet = null;
            $this->selectedQuotes = [];
            $this->checkoutError = $e->getMessage();

            return null;
        } catch (RuntimeException $e) {
            $this->checkoutError = $e->getMessage();

            return null;
        }

        // The freshly minted access token lets the confirmation page be read
        // once, without exposing anything by order number alone.
        session(['order_access_token' => app(CheckoutService::class)->attachAccessToken($order)]);

        try {
            $result = app(PaymentService::class)->begin($order, $gateway);
        } catch (GatewayNotAvailable $e) {
            $this->checkoutError = $e->getMessage();

            return null;
        } catch (Throwable $e) {
            report($e);
            $this->checkoutError = 'We could not start the payment. Nothing has been charged. Please try again.';

            return null;
        }

        $this->dispatch('cart-updated');

        if ($result['approval_url'] !== null) {
            return $this->redirect($result['approval_url'], navigate: false);
        }

        return $this->redirectRoute('checkout.confirmation', ['order' => $order->number], navigate: false);
    }

    public function guestCheckoutDisabled(): bool
    {
        return ! settings('checkout.guest_enabled', true) && ! auth()->check();
    }

    public function render(): View
    {
        $cart = $this->currentCart();
        $carts = app(CartService::class);
        $destination = $this->destination();
        $quotes = $this->quotes();

        $selectedShipping = null;

        if ($quotes !== null && ! $quotes->isBlocked() && $this->selectedQuotes !== []) {
            $chosen = $quotes->quotes->whereIn('id', array_values($this->selectedQuotes));
            $selectedShipping = \App\Support\Money\Money::ofMinor(
                (int) $chosen->sum(fn ($q) => (int) $q->getRawOriginal('amount_minor')),
                $cart->currency
            );
        }

        $registry = app(PaymentGatewayRegistry::class);

        return view('livewire.checkout-flow', [
            'cart' => $cart,
            'quotes' => $quotes,
            'totals' => $carts->totals($cart, $destination, $quotes, $selectedShipping),
            'gateways' => $registry->availableFor($cart->currency),
            'noGatewayReason' => $registry->unavailableReason($cart->currency),
            'guestDisabled' => $this->guestCheckoutDisabled(),
        ]);
    }
}
