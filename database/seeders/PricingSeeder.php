<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Market;
use App\Models\PricingRule;
use Illuminate\Database\Seeder;

class PricingSeeder extends Seeder
{
    public function run(): void
    {
        $us = Market::where('code', 'US')->first();

        // Global fallback so nothing is ever priced at cost by accident.
        PricingRule::updateOrCreate(
            ['name' => 'Global baseline markup'],
            [
                'scope' => PricingRule::SCOPE_GLOBAL,
                'scope_id' => null,
                'market_id' => null,
                'strategy' => PricingRule::STRATEGY_PERCENTAGE_MARKUP,
                'markup_percentage' => 180,
                // Floor covers shipping subsidy plus gateway fees, so a
                // discount that breaches it raises a warning.
                'min_contribution_minor' => 300,
                'rounding_mode' => 'up',
                'rounding_increment_minor' => 100,
                'rounding_ending_minor' => 99,
                'priority' => 100,
                'is_active' => true,
                'notes' => 'Fallback for anything without a more specific rule. Cost + 180% markup, rounded up to x.99.',
            ]
        );

        // Market rule beats the global one for US orders.
        if ($us !== null) {
            PricingRule::updateOrCreate(
                ['name' => 'US target margin'],
                [
                    'scope' => PricingRule::SCOPE_MARKET,
                    'scope_id' => null,
                    'market_id' => $us->id,
                    // Margin, not markup: 62% of the retail price is margin.
                    'strategy' => PricingRule::STRATEGY_TARGET_MARGIN,
                    'target_margin_percentage' => 62,
                    'min_contribution_minor' => 400,
                    'rounding_mode' => 'up',
                    'rounding_increment_minor' => 100,
                    'rounding_ending_minor' => 99,
                    'priority' => 50,
                    'is_active' => true,
                    'notes' => 'Targets a 62% gross margin on the retail price, then rounds up to x.99.',
                ]
            );
        }
    }
}
