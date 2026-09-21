<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Domain\Checkout\CartService;
use App\Models\Cart;
use App\Models\Market;

trait InteractsWithCart
{
    public function currentCart(): Cart
    {
        $service = app(CartService::class);
        $market = $this->currentMarket();

        $cart = $service->forToken(session('cart_token'), $market);

        if (session('cart_token') !== $cart->token) {
            session(['cart_token' => $cart->token]);
        }

        // Adopt a guest cart on login so nothing is lost at the door.
        if (auth()->check() && $cart->user_id === null) {
            $cart->forceFill(['user_id' => auth()->id()])->save();
        }

        return $cart->load('items.variant.product.media');
    }

    public function currentMarket(): Market
    {
        return Market::default();
    }
}
