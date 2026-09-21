<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_records', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 32)->default('standard');  // standard|branded_box
            // Uploading a logo never makes packaging available. State is advanced manually.
            $table->string('state', 32)->default('planned');  // planned|awaiting_approval|approved|stocking|available|unavailable
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_packaging_id')->nullable();
            $table->string('design_asset_path')->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('available_quantity')->nullable();
            $table->bigInteger('unit_cost_minor')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('added_weight_grams')->nullable();
            // Whether the supplier API can actually select/report this packaging.
            // Defaults to false: we do not claim API capability we have not verified.
            $table->boolean('api_selectable')->default(false);
            $table->boolean('api_reportable')->default(false);
            $table->text('manual_process_notes')->nullable();
            $table->text('readiness_notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamps();
            $table->index(['type', 'state']);
        });

        Schema::create('packaging_record_variant', function (Blueprint $table) {
            $table->foreignId('packaging_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->primary(['packaging_record_id', 'product_variant_id'], 'packaging_variant_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_record_variant');
        Schema::dropIfExists('packaging_records');
    }
};
