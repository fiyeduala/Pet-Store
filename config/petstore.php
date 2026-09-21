<?php

/**
 * Pet Store application configuration.
 *
 * Values here are deployment-level defaults. Anything the store owner should
 * be able to change at runtime lives in the `settings` table instead and is
 * read through the settings() helper.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Brand defaults
    |--------------------------------------------------------------------------
    | The customer-facing name is deliberately a placeholder. Nothing in the
    | codebase should hard-code it.
    */
    'brand' => [
        'name' => env('STORE_NAME', 'Pet Store'),
        'tagline' => env('STORE_TAGLINE', 'Considered supplies for dogs and cats'),
    ],

    'display_locale' => env('STORE_DISPLAY_LOCALE', 'en_US'),
    'display_timezone' => env('STORE_DISPLAY_TIMEZONE', 'America/New_York'),

    /*
    |--------------------------------------------------------------------------
    | Administration
    |--------------------------------------------------------------------------
    */
    'admin' => [
        // Turn on once every staff member has enrolled an authenticator app.
        'require_mfa' => env('ADMIN_REQUIRE_MFA', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo / live isolation
    |--------------------------------------------------------------------------
    | Demo mode is per-integration, held in the database. This flag only
    | governs whether demo seeding is permitted at all, and it is forced off
    | in production so a production deploy can never self-seed fake data.
    */
    'demo' => [
        'seeding_enabled' => env('DEMO_SEEDING_ENABLED', false),
        'banner' => env('DEMO_BANNER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supplier adapters
    |--------------------------------------------------------------------------
    */
    'suppliers' => [
        'cjdropshipping' => [
            /*
             | NOTE ON ENDPOINT PATHS
             |
             | These paths are configurable on purpose. They were written from
             | secondary sources because developers.cjdropshipping.com was not
             | reachable from the build environment, so they are UNVERIFIED.
             | Check each one against the live API reference before enabling
             | live mode; a correction is a config change, not a code change.
             | See docs/integration-notes.md.
             */
            'base_url' => env('CJ_BASE_URL', 'https://developers.cjdropshipping.com/api2.0/v1/'),
            'endpoints' => [
                'access_token' => 'authentication/getAccessToken',
                'refresh_token' => 'authentication/refreshAccessToken',
                'logout' => 'authentication/logout',
                'product_list' => 'product/list',
                'product_detail' => 'product/query',
                'product_variants' => 'product/variant/query',
                'variant_by_id' => 'product/variant/queryByVid',
                'stock_by_variant' => 'product/stock/queryByVid',
                'categories' => 'product/getCategory',
                'freight_calculate' => 'logistic/freightCalculate',
                'order_create' => 'shopping/order/createOrderV2',
                'order_detail' => 'shopping/order/getOrderDetail',
                'order_list' => 'shopping/order/list',
                'order_delete' => 'shopping/order/deleteOrder',
                'balance' => 'shopping/pay/getBalance',
                'pay_balance' => 'shopping/pay/payBalance',
                'track_info' => 'logistic/trackInfo',
            ],
            // CJ documents a 1 request/second limit on the token endpoint.
            // Other endpoints are throttled conservatively until measured.
            'rate_limits' => [
                'default_per_second' => (float) env('CJ_RATE_LIMIT_PER_SECOND', 1),
                'auth_per_second' => 0.2,
            ],
            'timeout_seconds' => (int) env('CJ_TIMEOUT_SECONDS', 30),
            'connect_timeout_seconds' => (int) env('CJ_CONNECT_TIMEOUT_SECONDS', 10),
            'retry_times' => (int) env('CJ_RETRY_TIMES', 2),
            // Tokens are long lived; refresh well before the documented expiry.
            'token_refresh_margin_hours' => 48,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    | Secrets are NEVER read from here into the browser. Only the documented
    | public client identifier is ever exposed to client-side code.
    */
    'payments' => [
        'paypal' => [
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            'live_base_url' => 'https://api-m.paypal.com',
            'sandbox_base_url' => 'https://api-m.sandbox.paypal.com',
        ],
        'paystack' => [
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        ],
        // Estimated gateway fee used in contribution reporting until the real
        // fee is reconciled from the provider. Basis points + fixed minor units.
        'fee_estimates' => [
            'paypal' => ['basis_points' => 349, 'fixed_minor' => 49],
            'paystack' => ['basis_points' => 390, 'fixed_minor' => 0],
            'demo' => ['basis_points' => 0, 'fixed_minor' => 0],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shipping quote caching
    |--------------------------------------------------------------------------
    */
    'shipping' => [
        'quote_ttl_minutes' => (int) env('SHIPPING_QUOTE_TTL_MINUTES', 20),
        'estimator_ttl_minutes' => (int) env('SHIPPING_ESTIMATOR_TTL_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest order access
    |--------------------------------------------------------------------------
    | Guest tracking links carry a high-entropy token. The order number alone
    | is never sufficient to view an order.
    */
    'guest_access' => [
        'token_bytes' => 32,
        'token_lifetime_days' => (int) env('GUEST_ORDER_TOKEN_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalogue sync
    |--------------------------------------------------------------------------
    */
    'sync' => [
        'stock_freshness_hours' => (int) env('STOCK_FRESHNESS_HOURS', 12),
        'catalogue_page_size' => (int) env('CJ_PAGE_SIZE', 50),
    ],
];
