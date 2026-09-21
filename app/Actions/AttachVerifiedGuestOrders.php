<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use App\Models\User;

/**
 * Links past guest orders to a newly verified account.
 *
 * This runs ONLY after the email address has been verified. Matching on a
 * typed address alone would let anyone claim another person's order history
 * simply by registering with their address.
 */
class AttachVerifiedGuestOrders
{
    public function handle(User $user): int
    {
        if (! $user->hasVerifiedEmail()) {
            return 0;
        }

        return Order::query()
            ->whereNull('user_id')
            ->where('is_guest', true)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])
            ->update(['user_id' => $user->id]);
    }
}
