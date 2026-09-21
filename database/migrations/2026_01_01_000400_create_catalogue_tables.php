<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('subtitle')->nullable();
            $table->longText('description')->nullable();
            $table->text('materials')->nullable();
            $table->text('care_instructions')->nullable();
            $table->text('suitable_for')->nullable();          // "Adult dogs 20-60 lb", owner written
            $table->string('brand')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('status', 16)->default('draft');    // draft|published|archived
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();

            // Supplier linkage. Source data is preserved, never overwritten in place.
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_product_id')->nullable();
            $table->json('supplier_source')->nullable();       // last raw payload from supplier
            $table->timestamp('supplier_synced_at')->nullable();
            // Per-field sync policy: fields listed here are owner-owned and never overwritten.
            $table->json('locked_fields')->nullable();

            $table->unsignedBigInteger('default_variant_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['supplier_id', 'supplier_product_id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('name')->nullable();
            $table->string('option_summary')->nullable();      // "Medium / Sage"
            $table->string('barcode')->nullable();

            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();

            // Money is always stored as integer minor units with an explicit ISO currency.
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('supplier_cost_minor')->nullable();
            $table->char('supplier_cost_currency', 3)->nullable();
            $table->bigInteger('computed_price_minor')->nullable();  // from pricing rules
            $table->bigInteger('manual_price_minor')->nullable();    // override; wins until cleared
            $table->bigInteger('compare_at_price_minor')->nullable();
            $table->bigInteger('sale_price_minor')->nullable();
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->string('applied_pricing_rule')->nullable();

            $table->string('supplier_variant_id')->nullable();
            $table->string('supplier_sku')->nullable();
            $table->json('supplier_source')->nullable();
            $table->timestamp('supplier_synced_at')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'position']);
            $table->index('supplier_variant_id');
        });

        Schema::create('variant_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('value_text')->nullable();
            $table->unique(['product_variant_id', 'attribute_id'], 'variant_attribute_unique');
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('value_text')->nullable();
            $table->unique(['product_id', 'attribute_id'], 'product_attribute_unique');
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->primary(['category_id', 'product_id']);
        });

        Schema::create('pet_type_product', function (Blueprint $table) {
            $table->foreignId('pet_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['pet_type_id', 'product_id']);
        });

        Schema::create('collection_product', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->primary(['collection_id', 'product_id']);
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('mediable');
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('alt')->nullable();
            $table->string('mime', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('position')->default(0);
            // Curated images survive supplier sync.
            $table->boolean('is_curated')->default(false);
            $table->string('source_url', 1024)->nullable();
            $table->timestamps();
        });

        // Per-market availability is modelled separately from stock.
        Schema::create('market_variant_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->bigInteger('price_override_minor')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['market_id', 'product_variant_id'], 'market_variant_unique');
        });

        Schema::create('supplier_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_variant_id');
            $table->bigInteger('cost_minor')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->boolean('is_available')->default(true);
            $table->json('source_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'product_variant_id'], 'supplier_offer_unique');
        });

        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_variant_id')->nullable();
            // quantity is NULL when the supplier did not report a number.
            // Unknown stock is never treated as unlimited stock.
            $table->integer('quantity')->nullable();
            $table->boolean('quantity_known')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('stale_after')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_variant_id'], 'warehouse_stock_unique');
            $table->index(['product_variant_id', 'quantity_known']);
        });
    }

    public function down(): void
    {
        foreach ([
            'warehouse_stocks', 'supplier_offers', 'market_variant_availability', 'media',
            'collection_product', 'pet_type_product', 'category_product',
            'product_attribute_values', 'variant_attribute_values',
            'product_variants', 'products',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
