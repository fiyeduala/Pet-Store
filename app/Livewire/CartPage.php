<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Checkout\CartService;
use App\Livewire\Concerns\InteractsWithCart;
use App\Models\CartItem;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class CartPage extends Component
{
    use InteractsWithCart;

    public string $discountCode = '';

    public ?string $discountMessage = null;

    public function updateQuantity(int $itemId, int $quantity): void
    {
        $cart = $this->currentCart();
        $item = $cart->items()->find($itemId);

        if ($item instanceof CartItem) {
            app(CartService::class)->updateQuantity($cart, $item, $quantity);
        }

        $this->dispatch('cart-updated');
    }

    public function remove(int $itemId): void
    {
        $cart = $this->currentCart();
        $item = $cart->items()->find($itemId);

        if ($item instanceof CartItem) {
            app(CartService::class)->remove($cart, $item);
        }

        $this->dispatch('cart-updated');
    }

    public function applyDiscount(): void
    {
        $result = app(CartService::class)->applyDiscount($this->currentCart(), $this->discountCode);
        $this->discountMessage = $result['message'];
    }

    public function removeDiscount(): void
    {
        app(CartService::class)->removeDiscount($this->currentCart());
        $this->discountCode = '';
        $this->discountMessage = null;
    }

    public function render(): View
    {
        $cart = $this->currentCart();

        return view('livewire.cart-page', [
            'cart' => $cart,
            'totals' => app(CartService::class)->totals($cart),
        ]);
    }
}
