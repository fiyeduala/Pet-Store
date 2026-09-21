<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithCart;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class CartButton extends Component
{
    use InteractsWithCart;

    public int $count = 0;

    public function mount(): void
    {
        $this->refreshCount();
    }

    #[On('cart-updated')]
    public function refreshCount(): void
    {
        $this->count = $this->currentCart()->itemCount();
    }

    public function render(): View
    {
        return view('livewire.cart-button');
    }
}
