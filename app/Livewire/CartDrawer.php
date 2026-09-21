<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Checkout\CartService;
use App\Livewire\Concerns\InteractsWithCart;
use App\Models\CartItem;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class CartDrawer extends Component
{
    use InteractsWithCart;

    public bool $open = false;

    #[On('open-cart-drawer')]
    public function openDrawer(): void
    {
        $this->open = true;
    }

    public function closeDrawer(): void
    {
        $this->open = false;
    }

    #[On('cart-updated')]
    public function refresh(): void
    {
        // Re-render only; the cart is read fresh in render().
    }

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

    public function render(): View
    {
        $cart = $this->currentCart();

        return view('livewire.cart-drawer', [
            'cart' => $cart,
            'totals' => app(CartService::class)->totals($cart),
        ]);
    }
}
