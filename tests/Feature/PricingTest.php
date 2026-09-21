<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pricing\PricingEngine;
use App\Models\Category;
use App\Models\Market;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    private Market $market;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\SettingsSeeder::class);

        $this->market = Market::create([
            'code' => 'US', 'name' => 'United States', 'currency' => 'USD',
            'is_enabled' => true, 'is_default' => true, 'tax_mode' => 'disabled',
            'preferred_warehouse_countries' => ['US'],
        ]);

        PricingRule::query()->delete();
    }

    #[Test]
    public function percentage_markup_adds_to_cost(): void
    {
        $this->rule(['strategy' => PricingRule::STRATEGY_PERCENTAGE_MARKUP, 'markup_percentage' => 100]);
        $variant = $this->variant(costMinor: 1000);

        // 100% markup on $10.00 cost is $20.00 retail.
        $this->assertSame(2000, app(PricingEngine::class)->computeRulePrice($variant, $this->market)->minor);
    }

    #[Test]
    public function target_margin_is_a_share_of_retail_not_of_cost(): void
    {
        $this->rule(['strategy' => PricingRule::STRATEGY_TARGET_MARGIN, 'target_margin_percentage' => 50]);
        $variant = $this->variant(costMinor: 1000);

        // 50% MARGIN on $10.00 cost is $20.00 retail (cost / (1 - 0.5)),
        // which happens to equal a 100% MARKUP. The two concepts are only
        // equal at this one point, which is exactly why they are separate.
        $price = app(PricingEngine::class)->computeRulePrice($variant, $this->market);
        $this->assertSame(2000, $price->minor);
    }

    #[Test]
    public function margin_and_markup_diverge_and_are_reported_separately(): void
    {
        $this->rule(['strategy' => PricingRule::STRATEGY_TARGET_MARGIN, 'target_margin_percentage' => 60]);
        $variant = $this->variant(costMinor: 1000);

        // 60% margin: retail = 10 / 0.4 = $25.00. That is a 150% markup.
        $price = app(PricingEngine::class)->computeRulePrice($variant, $this->market);
        $this->assertSame(2500, $price->minor);

        $variant->forceFill(['computed_price_minor' => 2500])->save();
        $breakdown = app(PricingEngine::class)->breakdown($variant->fresh(), $this->market);

        $this->assertNotSame($breakdown->marginPercent(), $breakdown->markupPercent());
    }

    #[Test]
    public function a_more_specific_rule_wins(): void
    {
        $this->rule(['name' => 'global', 'strategy' => PricingRule::STRATEGY_PERCENTAGE_MARKUP, 'markup_percentage' => 100]);

        $category = Category::create(['slug' => 'toys', 'name' => 'Toys']);
        $variant = $this->variant(costMinor: 1000);
        $variant->product->categories()->attach($category);

        $this->rule([
            'name' => 'category',
            'scope' => PricingRule::SCOPE_CATEGORY,
            'scope_id' => $category->id,
            'strategy' => PricingRule::STRATEGY_PERCENTAGE_MARKUP,
            'markup_percentage' => 200,
        ]);

        // Category beats global: 200% markup, not 100%.
        $resolved = app(PricingEngine::class)->resolveRule($variant->fresh(), $this->market);
        $this->assertSame('category', $resolved->name);
        $this->assertSame(3000, app(PricingEngine::class)->computeRulePrice($variant->fresh(), $this->market)->minor);
    }

    #[Test]
    public function a_product_rule_beats_a_category_rule(): void
    {
        $category = Category::create(['slug' => 'toys', 'name' => 'Toys']);
        $variant = $this->variant(costMinor: 1000);
        $variant->product->categories()->attach($category);

        $this->rule(['name' => 'category', 'scope' => PricingRule::SCOPE_CATEGORY, 'scope_id' => $category->id,
            'strategy' => PricingRule::STRATEGY_PERCENTAGE_MARKUP, 'markup_percentage' => 200]);
        $this->rule(['name' => 'product', 'scope' => PricingRule::SCOPE_PRODUCT, 'scope_id' => $variant->product_id,
            'strategy' => PricingRule::STRATEGY_PERCENTAGE_MARKUP, 'markup_percentage' => 300]);

        $this->assertSame('product', app(PricingEngine::class)->resolveRule($variant->fresh(), $this->market)->name);
    }

    #[Test]
    public function a_manual_override_beats_every_rule_until_cleared(): void
    {
        $this->rule(['strategy' => PricingRule::STRATEGY_PERCENTAGE_MARKUP, 'markup_percentage' => 100]);
        $variant = $this->variant(costMinor: 1000);

        app(PricingEngine::class)->repriceVariant($variant, $this->market);
        $this->assertSame(2000, $variant->fresh()->effectivePriceMinor());

        // Setting a manual price takes over.
        $variant->forceFill(['manual_price_minor' => 4999])->save();
        $this->assertSame(4999, $variant->fresh()->effectivePriceMinor());

        // Repricing must NOT wipe the override.
        app(PricingEngine::class)->repriceVariant($variant->fresh(), $this->market);
        $this->assertSame(4999, $variant->fresh()->effectivePriceMinor());
        $this->assertSame(2000, (int) $variant->fresh()->getRawOriginal('computed_price_minor'));

        // Clearing it hands control back to the rule.
        $variant->forceFill(['manual_price_minor' => null])->save();
        $this->assertSame(2000, $variant->fresh()->effectivePriceMinor());
    }

    #[Test]
    public function a_breached_contribution_floor_is_reported(): void
    {
        $this->rule([
            'strategy' => PricingRule::STRATEGY_PERCENTAGE_MARKUP,
            'markup_percentage' => 10,
            'min_contribution_minor' => 5000,
        ]);

        $variant = $this->variant(costMinor: 1000);
        $breakdown = app(PricingEngine::class)->breakdown($variant, $this->market);

        $this->assertTrue($breakdown->breachesFloor);
        $this->assertNotEmpty($breakdown->warnings);
    }

    #[Test]
    public function a_cost_in_another_currency_is_refused_rather_than_guessed(): void
    {
        $this->rule(['strategy' => PricingRule::STRATEGY_PERCENTAGE_MARKUP, 'markup_percentage' => 100]);

        $variant = $this->variant(costMinor: 1000);
        $variant->forceFill(['supplier_cost_currency' => 'NGN'])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configure a conversion policy');

        app(PricingEngine::class)->computeRulePrice($variant->fresh(), $this->market);
    }

    #[Test]
    public function a_target_margin_of_one_hundred_percent_is_rejected(): void
    {
        $this->rule(['strategy' => PricingRule::STRATEGY_TARGET_MARGIN, 'target_margin_percentage' => 100]);
        $variant = $this->variant(costMinor: 1000);

        $this->expectException(\RuntimeException::class);
        app(PricingEngine::class)->computeRulePrice($variant, $this->market);
    }

    private function rule(array $overrides = []): PricingRule
    {
        return PricingRule::create($overrides + [
            'name' => 'rule-'.PricingRule::count(),
            'scope' => PricingRule::SCOPE_GLOBAL,
            'min_contribution_minor' => 0,
            'rounding_mode' => 'none',
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    private function variant(int $costMinor): ProductVariant
    {
        $product = Product::create([
            'slug' => 'p-'.uniqid(),
            'name' => 'Test product',
            'status' => Product::STATUS_DRAFT,
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.uniqid(),
            'currency' => 'USD',
            'supplier_cost_minor' => $costMinor,
            'supplier_cost_currency' => 'USD',
            'is_active' => true,
        ]);
    }
}
