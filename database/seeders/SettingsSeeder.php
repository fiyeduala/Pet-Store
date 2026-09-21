<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Settings\SettingsRepository;
use Illuminate\Database\Seeder;

/**
 * Baseline owner-editable settings.
 *
 * These are conservative defaults chosen so the store cannot make a claim
 * it cannot back up before the owner has configured anything.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(SettingsRepository::class);

        $settings->setMany([
            // Brand. The name is a placeholder until the owner chooses one.
            'brand.name' => 'Pet Store',
            'brand.tagline' => 'Considered supplies for dogs and cats',
            'brand.accent_color' => '#3E7C78',
            'brand.accent_contrast' => '#FFFFFF',
            'brand.social' => [],
        ], 'brand');

        $settings->setMany([
            'contact.support_email' => '',
            'contact.support_phone' => '',
            'contact.support_hours' => 'Monday to Friday, 9am–5pm ET',
        ], 'contact');

        $settings->setMany([
            // Left blank deliberately: inventing a business address or a
            // registration number would be a fabrication.
            'business.legal_name' => '',
            'business.address' => '',
            'business.registration' => '',
        ], 'business');

        $settings->setMany([
            'seo.default_title' => 'Pet Store — considered supplies for dogs and cats',
            'seo.default_description' => 'Toys, enrichment, feeding, grooming, travel and beds for dogs and cats, shipped from US warehouses.',
        ], 'seo');

        $settings->setMany([
            // Guest checkout on by default; the owner can require accounts.
            'checkout.guest_enabled' => true,
        ], 'checkout');

        $settings->setMany([
            // Both automation switches OFF. Every paid order waits for a
            // human before anything is bought from the supplier.
            'fulfilment.auto_approve' => false,
            'fulfilment.auto_submit' => false,
            'fulfilment.auto_pay_supplier' => false,
            'fulfilment.auto_approve_daily_limit' => 0,
            'fulfilment.auto_approve_order_value_limit_minor' => 0,
            'fulfilment.cost_increase_tolerance_bp' => 1000,
        ], 'fulfilment');

        $settings->setMany([
            'inventory.stock_buffer' => 1,
        ], 'inventory');

        $settings->setMany([
            'pricing.estimated_shipping_cost_minor' => 600,
        ], 'pricing');

        $settings->setMany([
            'shipping.default_weight_grams' => 500,
            'shipping.handling_min_days' => 1,
            'shipping.handling_max_days' => 3,
            'shipping.pre_destination_message' =>
                'Delivery time depends on your address and which warehouse has stock. Enter your ZIP code for an estimate.',
            'shipping.estimate_unavailable_message' =>
                'A delivery estimate is not available for this service.',
        ], 'shipping');

        $settings->setMany([
            // Standard packaging only until branded packaging is verified.
            'packaging.default_choice' => 'standard',
            'packaging.shortage_policy' => 'fallback_standard',
        ], 'packaging');
    }
}
