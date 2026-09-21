<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\AttachVerifiedGuestOrders;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    public function __invoke(EmailVerificationRequest $request, AttachVerifiedGuestOrders $attach): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('account.dashboard');
        }

        $request->fulfill();

        // Only now, with the address proven, may past guest orders be linked.
        $linked = $attach->handle($request->user());

        return redirect()->route('account.dashboard')->with(
            'status',
            $linked > 0
                ? "Email verified. We found {$linked} previous order(s) placed with this address and added them to your account."
                : 'Email verified.'
        );
    }
}
