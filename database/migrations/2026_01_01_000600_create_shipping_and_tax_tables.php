<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('match_type', 16);                 // country|state|zip_prefix|rest
            $table->json('codes')->nullable();                // ["AK","HI"] or ["995","996"]
            // Exclusion zones block checkout for that destination with an explicit message.
            $table->boolean('is_excluded')->default(false);
            $table->text('exclusion_message')->nullable();
            $table->boolean('blocks_po_boxes')->default(false);
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['market_id', 'priority']);
        });

        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
            $table->string('name');                            // customer-facing service name
            $table->string('mode', 24);                        // quoted|flat|free_threshold
            $table->bigInteger('flat_amount_minor')->nullable();
            $table->bigInteger('free_threshold_minor')->nullable();
            $table->char('currency', 3)->default('USD');
            // Owner-managed delivery policy used ONLY when the supplier gives no estimate.
            $table->unsignedInteger('policy_min_days')->nullable();
            $table->unsignedInteger('policy_max_days')->nullable();
            $table->string('policy_day_unit', 24)->nullable(); // business_days|days
            $table->string('policy_estimate_type', 24)->nullable(); // transit|total
            $table->unsignedInteger('handling_min_days')->nullable();
            $table->unsignedInteger('handling_max_days')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('quote_group', 64)->index();        // groups parcels of one request
            $table->string('destination_hash', 64)->index();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_code')->nullable();
            $table->string('service_name');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('supplier_cost_minor')->nullable();

            // Delivery estimates are stored with full provenance and are never invented.
            $table->unsignedInteger('estimate_min')->nullable();
            $table->unsignedInteger('estimate_max')->nullable();
            $table->string('estimate_unit', 24)->default('unknown');  // business_days|days|unknown
            $table->string('estimate_type', 24)->default('unknown');  // transit|processing|total|unknown
            $table->unsignedInteger('handling_min_days')->nullable(); // separately supplied
            $table->unsignedInteger('handling_max_days')->nullable();
            $table->string('estimate_source', 32)->default('unavailable'); // supplier_api|owner_policy|flat_rate|unavailable
            $table->string('rate_source', 32)->default('flat_rate');       // supplier_api|flat_rate|free_threshold

            $table->boolean('is_demo')->default(false);
            $table->timestamp('quoted_at');
            $table->timestamp('expires_at');
            $table->json('raw_payload')->nullable();
            $table->json('line_allocation')->nullable();       // which order/cart lines this parcel covers
            $table->timestamps();
        });

        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('match_type', 16);                  // country|state|zip_prefix
            $table->json('codes')->nullable();
            // Basis points avoid float arithmetic: 725 = 7.25%.
            $table->unsignedInteger('rate_basis_points');
            $table->boolean('applies_to_shipping')->default(false);
            $table->boolean('is_active')->default(false);
            // Manual tax must be explicit and auditable.
            $table->string('configured_by')->nullable();
            $table->timestamp('configured_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('shipping_quotes');
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_zones');
    }
};
