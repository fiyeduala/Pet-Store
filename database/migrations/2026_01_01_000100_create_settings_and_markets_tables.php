<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group')->index();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('type', 32)->default('string');
            $table->boolean('is_encrypted')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();           // ISO 3166-1 alpha-2, e.g. US
            $table->string('name');
            $table->char('currency', 3);                    // ISO 4217, e.g. USD
            $table->string('display_timezone')->default('America/New_York');
            $table->string('locale', 16)->default('en_US');
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_default')->default(false);
            // Tax is never assumed. Owner must configure explicitly.
            $table->string('tax_mode', 32)->default('disabled'); // disabled|manual|provider
            $table->string('tax_provider', 64)->nullable();
            $table->boolean('prices_include_tax')->default(false);
            // Overseas fulfilment must be a deliberate decision, never a silent fallback.
            $table->boolean('allow_overseas_fulfilment')->default(false);
            $table->json('preferred_warehouse_countries')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 20, 10);
            $table->string('source', 64);
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->index(['base_currency', 'quote_currency', 'fetched_at'], 'exchange_rates_pair_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('markets');
        Schema::dropIfExists('settings');
    }
};
