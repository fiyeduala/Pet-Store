<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PaymentGateway;
use Illuminate\Database\Seeder;

class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        PaymentGateway::updateOrCreate(
            ['code' => 'paypal'],
            [
                'name' => 'PayPal',
                // Preferred in the owner's plan, but NOT enabled: whether the
                // merchant account can accept commercial USD Checkout
                // payments has not been confirmed.
                'is_enabled' => false,
                'mode' => PaymentGateway::MODE_SANDBOX,
                'is_configured' => filled(config('petstore.payments.paypal.client_id')),
                'is_verified' => false,
                'supported_currencies' => ['USD'],
                'position' => 10,
                'capability_notes' => implode(' ', [
                    'The owner must confirm with PayPal that this account is a business account able to',
                    'receive commercial Checkout payments in USD, and that it is not restricted by the',
                    'account country. A personal account, or an account registered in a country PayPal',
                    'does not allow to receive merchant payments in USD, cannot be used here.',
                    'Sandbox success does not prove any of this.',
                ]),
            ]
        );

        PaymentGateway::updateOrCreate(
            ['code' => 'paystack'],
            [
                'name' => 'Paystack',
                'is_enabled' => false,
                'mode' => PaymentGateway::MODE_SANDBOX,
                'is_configured' => filled(config('petstore.payments.paystack.secret_key')),
                'is_verified' => false,
                // Empty on purpose: do not assume USD is available. Many
                // Paystack accounts settle only in NGN.
                'supported_currencies' => [],
                'position' => 20,
                'capability_notes' => implode(' ', [
                    'The owner must confirm with Paystack which currencies this account may charge and',
                    'settle in. Do not add USD here until Paystack has confirmed USD support in writing.',
                    'If only NGN is available, this method must stay disabled for the USD storefront:',
                    'the store will not silently charge a different currency.',
                ]),
            ]
        );

        PaymentGateway::updateOrCreate(
            ['code' => 'demo'],
            [
                'name' => 'Simulated payment (demo)',
                // Enabled so the full journey is walkable before any real
                // gateway is live. It can never take money.
                'is_enabled' => true,
                'mode' => PaymentGateway::MODE_DEMO,
                'is_configured' => true,
                'is_verified' => false,
                'supported_currencies' => ['USD'],
                'position' => 90,
                'capability_notes' => 'Simulated only. Turn this off before going live.',
            ]
        );
    }
}
