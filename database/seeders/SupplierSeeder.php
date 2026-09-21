<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $cj = Supplier::updateOrCreate(
            ['code' => 'cjdropshipping'],
            [
                'name' => 'CJdropshipping',
                'adapter' => 'cjdropshipping',
                // Starts in DEMO. Switching to live is a deliberate act that
                // requires credentials and a successful verification run.
                'mode' => Supplier::MODE_DEMO,
                'is_enabled' => true,
                'connection_state' => 'unknown',
                'settings' => ['currency' => 'USD'],
                // No capability is claimed until it has been observed.
                'capabilities' => [
                    'packaging_selection' => false,
                    'webhooks' => false,
                ],
            ]
        );

        // Demo warehouses mirror the demo adapter's warehouse codes so the
        // whole flow works before any real credentials exist.
        foreach ([
            ['US-NJ', 'Demo New Jersey', 'US', 'NJ', 10, true],
            ['US-CA', 'Demo California', 'US', 'CA', 20, true],
            // Overseas warehouse exists but is DISABLED: running out of US
            // stock must never silently ship from China.
            ['CN-GZ', 'Demo Guangzhou', 'CN', null, 900, false],
        ] as [$code, $name, $country, $state, $priority, $enabled]) {
            Warehouse::updateOrCreate(
                ['supplier_id' => $cj->id, 'code' => $code],
                [
                    'name' => $name,
                    'country_code' => $country,
                    'state_code' => $state,
                    'priority' => $priority,
                    'is_enabled' => $enabled,
                    'notes' => $enabled
                        ? null
                        : 'Overseas warehouse. Enabling this allows orders to ship from outside the US; disclose this to customers before doing so.',
                ]
            );
        }
    }
}
