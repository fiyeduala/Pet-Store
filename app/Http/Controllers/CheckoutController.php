<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Payments\PaymentGatewayRegistry;
use App\Domain\Payments\PaymentService;
use App\Models\Order;
use App\Models\PaymentGateway;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentGatewayRegistry $gateways,
    ) {}

    public function index(): View
    {
        return view('storefront.checkout');
    }

    /**
     * The customer has come back from the gateway.
     *
     * This does NOT mark anything paid. It triggers a server-side capture
     * and reports whatever the provider actually says.
     */
    public function paymentReturn(Request $request, string $gateway, Order $order): RedirectResponse
    {
        $payment = $order->payments()
            ->where('gateway_code', $gateway)
            ->latest('id')
            ->first();

        if ($payment === null) {
            return redirect()->route('checkout.index')
                ->with('error', 'We could not find that payment. Nothing has been charged.');
        }

        $payment = $this->payments->captureAndApply($payment);

        if (! $payment->isCaptured()) {
            return redirect()->route('checkout.index')->with(
                'error',
                $payment->last_error ?: 'That payment was not completed. You have not been charged.'
            );
        }

        return redirect()->route('checkout.confirmation', $order)
            ->with('order_access_token', $request->session()->get('order_access_token'));
    }

    public function paymentCancel(string $gateway, Order $order): RedirectResponse
    {
        return redirect()->route('cart.index')
            ->with('status', 'Payment was cancelled. Your basket is still here and nothing has been charged.');
    }

    /**
     * Demo settlement screen. Refuses to run for anything but a demo
     * gateway, so it can never be used to fake a live payment.
     */
    public function demoConfirm(Order $order): View
    {
        $payment = $order->payments()->latest('id')->firstOrFail();

        abort_unless($payment->is_demo, 403, 'Demo settlement is only available for simulated payments.');

        return view('storefront.checkout-demo', ['order' => $order, 'payment' => $payment]);
    }

    public function demoSettle(Order $order): RedirectResponse
    {
        $payment = $order->payments()->latest('id')->firstOrFail();

        abort_unless($payment->is_demo, 403, 'Demo settlement is only available for simulated payments.');

        $payment = $this->payments->captureAndApply($payment);

        if (! $payment->isCaptured()) {
            return back()->with('error', $payment->last_error ?: 'The simulated payment did not succeed.');
        }

        return redirect()->route('checkout.confirmation', $order);
    }

    public function confirmation(Request $request, Order $order): View
    {
        // A just-placed order is readable from the session token; otherwise
        // the signed tracking link or an owning account is required.
        $token = $request->session()->get('order_access_token');
        $ownsByToken = $token !== null && hash('sha256', $token) === $order->access_token_hash;
        $ownsByAccount = $request->user() !== null && $order->user_id === $request->user()->id;

        abort_unless($ownsByToken || $ownsByAccount, 404);

        $order->load(['items', 'shipments']);

        return view('storefront.confirmation', ['order' => $order]);
    }
}
