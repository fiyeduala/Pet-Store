<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Market;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Database\Seeder;

class MarketSeeder extends Seeder
{
    public function run(): void
    {
        $us = Market::updateOrCreate(
            ['code' => 'US'],
            [
                'name' => 'United States',
                'currency' => 'USD',
                'display_timezone' => 'America/New_York',
                'locale' => 'en_US',
                'is_enabled' => true,
                'is_default' => true,
                // Tax is OFF until the owner and their accountant configure it.
                'tax_mode' => 'disabled',
                'prices_include_tax' => false,
                // Domestic-only. Overseas fulfilment is a deliberate choice.
                'allow_overseas_fulfilment' => false,
                'preferred_warehouse_countries' => ['US'],
                'notes' => 'Launch market. Tax is disabled until rules are configured and confirmed with an accountant.',
            ]
        );

        // Other markets exist but are disabled; they can be enabled later.
        foreach ([
            ['CA', 'Canada', 'CAD', 'America/Toronto'],
            ['GB', 'United Kingdom', 'GBP', 'Europe/London'],
        ] as [$code, $name, $currency, $tz]) {
            Market::updateOrCreate(['code' => $code], [
                'name' => $name,
                'currency' => $currency,
                'display_timezone' => $tz,
                'is_enabled' => false,
                'is_default' => false,
                'tax_mode' => 'disabled',
                'preferred_warehouse_countries' => [$code],
                'notes' => 'Disabled. Enable only after confirming supplier shipping, tax and payment support.',
            ]);
        }

        $this->seedZones($us);
    }

    private function seedZones(Market $market): void
    {
        // Contiguous US: quoted carrier rates with free shipping above a
        // threshold the owner can change.
        $mainland = ShippingZone::updateOrCreate(
            ['market_id' => $market->id, 'name' => 'Contiguous United States'],
            [
                'match_type' => ShippingZone::MATCH_COUNTRY,
                'codes' => ['US'],
                'is_excluded' => false,
                'blocks_po_boxes' => false,
                'priority' => 100,
                'is_active' => true,
            ]
        );

        ShippingRate::updateOrCreate(
            ['shipping_zone_id' => $mainland->id, 'name' => 'Standard delivery'],
            [
                'mode' => ShippingRate::MODE_FREE_THRESHOLD,
                'free_threshold_minor' => 5000,
                'flat_amount_minor' => 599,
                'currency' => 'USD',
                'handling_min_days' => 1,
                'handling_max_days' => 3,
                'is_active' => true,
                'position' => 0,
            ]
        );

        // Alaska, Hawaii and the territories have different service
        // availability, so they get their own zone rather than being
        // silently quoted as if they were mainland.
        $remote = ShippingZone::updateOrCreate(
            ['market_id' => $market->id, 'name' => 'Alaska, Hawaii and US territories'],
            [
                'match_type' => ShippingZone::MATCH_STATE,
                'codes' => ['AK', 'HI', 'PR', 'VI', 'GU', 'AS', 'MP'],
                'is_excluded' => false,
                // Many of the available services cannot deliver to PO boxes.
                'blocks_po_boxes' => true,
                'priority' => 50,
                'is_active' => true,
            ]
        );

        ShippingRate::updateOrCreate(
            ['shipping_zone_id' => $remote->id, 'name' => 'Standard delivery'],
            [
                'mode' => ShippingRate::MODE_QUOTED,
                'currency' => 'USD',
                'handling_min_days' => 1,
                'handling_max_days' => 3,
                'is_active' => true,
                'position' => 0,
            ]
        );

        // APO/FPO/DPO military addresses need confirmed carrier support.
        ShippingZone::updateOrCreate(
            ['market_id' => $market->id, 'name' => 'Military (APO/FPO/DPO)'],
            [
                'match_type' => ShippingZone::MATCH_STATE,
                'codes' => ['AA', 'AE', 'AP'],
                'is_excluded' => true,
                'exclusion_message' => 'We cannot currently ship to APO, FPO or DPO addresses. Please contact us before ordering.',
                'priority' => 10,
                'is_active' => true,
            ]
        );
    }
}
