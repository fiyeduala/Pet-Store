<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Notifications\GuestOrderAccessLink;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Guest order access.
 *
 * Knowing an order number is never enough. The visitor proves control of
 * the email address on the order, and we mail a signed, expiring link.
 * The response is identical whether or not the order exists, so this
 * cannot be used to enumerate orders.
 */
class OrderTrackingController extends Controller
{
    public function form(): View
    {
        return view('storefront.track');
    }

    public function request(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order_number' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email:rfc', 'max:190'],
        ]);

        $order = Order::query()
            ->where('number', $data['order_number'])
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])
            ->first();

        if ($order !== null) {
            $token = app(\App\Domain\Checkout\CheckoutService::class)->attachAccessToken($order);

            $url = URL::temporarySignedRoute(
                'orders.track.show',
                now()->addDays(7),
                ['order' => $order->number, 'token' => $token],
            );

            Notification::route('mail', $order->email)->notify(new GuestOrderAccessLink($order, $url));
        }

        // Deliberately identical either way.
        return back()->with('status', 'If that order exists, we have emailed a secure link to view it.');
    }

    public function show(Request $request, Order $order): View
    {
        $token = (string) $request->query('token');

        // Both the signature (route middleware) and the token must check out.
        abort_unless(
            $order->access_token_hash !== null
            && hash_equals($order->access_token_hash, hash('sha256', $token)),
            404
        );

        abort_if(
            $order->access_token_expires_at !== null && $order->access_token_expires_at->isPast(),
            410,
            'This tracking link has expired. Please request a new one.'
        );

        $order->load(['items', 'shipments.items', 'refunds', 'returnRequests']);

        return view('storefront.track-order', ['order' => $order]);
    }
}
