<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Precedence (most specific wins): product > category > market > global.
            // Ties inside a scope are broken by `priority` ascending, then id ascending.
            $table->string('scope', 16);                      // global|market|category|product
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('market_id')->nullable()->constrained()->cascadeOnDelete();

            // markup != margin. Both are supported and named unambiguously.
            $table->string('strategy', 32);                   // fixed_markup|percentage_markup|target_margin
            $table->bigInteger('markup_amount_minor')->nullable();
            $table->decimal('markup_percentage', 8, 4)->nullable();   // added on top of cost
            $table->decimal('target_margin_percentage', 8, 4)->nullable(); // share of retail price

            // Contribution floor: retail - cost - est. shipping - packaging - est. fees.
            $table->bigInteger('min_contribution_minor')->default(0);
            $table->string('rounding_mode', 24)->default('none'); // none|up|down|nearest
            $table->unsignedInteger('rounding_increment_minor')->nullable(); // e.g. 100 = whole dollar
            $table->integer('rounding_ending_minor')->nullable();            // e.g. 99 -> x.99

            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['scope', 'scope_id', 'is_active']);
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type', 24);                       // percentage|fixed|free_shipping
            $table->decimal('percentage', 8, 4)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('min_subtotal_minor')->nullable();
            $table->foreignId('market_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('applies_to')->nullable();           // {categories:[], products:[]}
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('discount_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('email')->nullable();
            $table->bigInteger('amount_minor');
            $table->timestamps();
            $table->index(['discount_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_redemptions');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('pricing_rules');
    }
};
