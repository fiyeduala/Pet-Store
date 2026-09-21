<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Baseline seed.
 *
 * This installs only what a real store needs: settings, roles, the US
 * market, the supplier record and the payment gateway records. It does NOT
 * seed sample products — run DemoCatalogueSeeder for that, and only outside
 * production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            RoleSeeder::class,
            MarketSeeder::class,
            SupplierSeeder::class,
            PaymentGatewaySeeder::class,
            TaxonomySeeder::class,
            PricingSeeder::class,
            ContentSeeder::class,
        ]);
    }
}
