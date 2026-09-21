<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function dashboard(Request $request): View
    {
        return view('storefront.account.dashboard', [
            'recentOrders' => $request->user()->orders()->latest()->limit(5)->get(),
        ]);
    }

    public function orders(Request $request): View
    {
        // Guests reach this page from the header; offer tracking instead of
        // a login wall, since guest checkout is supported.
        if (! $request->user()) {
            return view('storefront.account.guest-orders');
        }

        return view('storefront.account.orders', [
            'orders' => $request->user()->orders()->latest()->paginate(10),
        ]);
    }

    public function order(Request $request, Order $order): View
    {
        // Ownership is checked explicitly; never trust the URL alone.
        abort_unless($order->user_id === $request->user()->id, 404);

        $order->load(['items', 'shipments.items', 'refunds', 'returnRequests']);

        return view('storefront.account.order', ['order' => $order]);
    }

    public function addresses(Request $request): View
    {
        return view('storefront.account.addresses', [
            'addresses' => $request->user()->addresses()->get(),
        ]);
    }

    public function profile(Request $request): View
    {
        return view('storefront.account.profile');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'accepts_marketing' => ['boolean'],
        ]);

        $request->user()->update($data + ['accepts_marketing' => $request->boolean('accepts_marketing')]);

        return back()->with('status', 'Profile updated.');
    }
}
