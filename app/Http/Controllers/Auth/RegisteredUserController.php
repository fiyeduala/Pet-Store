<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'confirmed', Password::defaults()->min(10)->mixedCase()->numbers()],
        ]);

        $user = User::create($data + ['is_staff' => false]);

        event(new Registered($user));
        Auth::login($user);

        // Past guest orders are NOT attached here. Matching on the email
        // address alone would hand someone else's order history to whoever
        // registers with that address first. Linking happens only after the
        // address is verified — see AttachVerifiedGuestOrders.
        return redirect()->route('verification.notice');
    }
}
