<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_label')->nullable();
            $table->string('action');
            $table->nullableMorphs('auditable');
            $table->json('changes')->nullable();   // secrets redacted before write
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['action', 'created_at']);
        });

        // Anything that needs a human decision lands here with a suggested next action.
        Schema::create('operational_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            //   supplier_timeout|supplier_rejected|insufficient_balance|stock_changed|
            //   cost_changed|service_unavailable|payment_mismatch|refund_dispatch_race|
            //   packaging_shortage|mixed_mode_blocked|webhook_unverified
            $table->string('severity', 16)->default('warning'); // info|warning|critical
            $table->string('state', 24)->default('open');       // open|acknowledged|resolved|dismissed
            $table->string('title');
            $table->text('detail')->nullable();
            $table->text('suggested_action')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fulfilment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->index(['state', 'severity']);
        });

        // Durable outbox so a crash between "DB committed" and "side effect sent"
        // never silently loses the side effect.
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->json('payload');
            $table->string('dedupe_key')->nullable()->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['processed_at', 'available_at']);
        });

        // Scheduler and queue-worker liveness, surfaced in admin.
        Schema::create('heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();      // scheduler|queue_worker|cj_stock_sync|...
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 24)->nullable();
            $table->text('last_message')->nullable();
            $table->unsignedInteger('expected_interval_minutes')->default(60);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['heartbeats', 'outbox_messages', 'operational_exceptions', 'audit_logs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
