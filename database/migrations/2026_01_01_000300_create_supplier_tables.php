<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                 // cjdropshipping
            $table->string('name');
            $table->string('adapter');                        // service container key
            // Explicit per-integration mode. Never inferred, never auto-downgraded.
            $table->string('mode', 16)->default('demo');      // demo|sandbox|live
            $table->boolean('is_enabled')->default(true);
            $table->text('credentials')->nullable();          // encrypted JSON blob
            $table->json('settings')->nullable();             // non-secret settings
            $table->string('connection_state', 24)->default('unknown'); // unknown|ok|auth_failed|error
            $table->timestamp('last_authenticated_at')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('capabilities')->nullable();         // observed/declared capability map
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->char('country_code', 2);
            $table->string('state_code', 8)->nullable();
            $table->string('city')->nullable();
            $table->boolean('is_enabled')->default(true);
            // Lower number wins in deterministic warehouse selection.
            $table->unsignedInteger('priority')->default(100);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'code']);
            $table->index(['country_code', 'is_enabled']);
        });

        Schema::create('supplier_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48);                       // catalogue|stock|cost|order|tracking|auth
            $table->string('status', 24)->default('running'); // running|success|partial|failed
            $table->string('mode', 16)->default('demo');
            $table->string('reference')->nullable();
            $table->unsignedInteger('items_processed')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->json('context')->nullable();              // redacted request/response summary
            $table->text('error')->nullable();
            $table->foreignId('retry_of_id')->nullable()->constrained('supplier_sync_logs')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_sync_logs');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('suppliers');
    }
};
