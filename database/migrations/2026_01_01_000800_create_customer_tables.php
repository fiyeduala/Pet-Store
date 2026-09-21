<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->boolean('is_staff')->default(false)->after('phone');
            $table->boolean('accepts_marketing')->default(false)->after('is_staff');
            $table->text('app_authentication_secret')->nullable();       // encrypted
            $table->text('app_authentication_recovery_codes')->nullable(); // encrypted
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('company')->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city');
            $table->string('state', 64)->nullable();
            $table->string('postal_code', 32);
            $table->char('country_code', 2)->default('US');
            $table->string('phone')->nullable();
            $table->boolean('is_default_shipping')->default(false);
            $table->boolean('is_default_billing')->default(false);
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->string('email')->nullable();
            $table->json('shipping_address')->nullable();
            $table->foreignId('discount_id')->nullable()->constrained()->nullOnDelete();
            $table->string('selected_quote_group', 64)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->index('last_activity_at');
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_price_minor');
            $table->char('currency', 3)->default('USD');
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->unique(['cart_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('addresses');
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'phone', 'is_staff', 'accepts_marketing',
                'app_authentication_secret', 'app_authentication_recovery_codes', 'last_login_at',
            ]);
        });
    }
};
