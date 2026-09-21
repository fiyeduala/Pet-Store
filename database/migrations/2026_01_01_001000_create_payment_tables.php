<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                 // paypal|paystack|demo
            $table->string('name');
            $table->boolean('is_enabled')->default(false);
            $table->string('mode', 16)->default('demo');      // demo|sandbox|live
            // "Configured" (we have credentials) is NOT "verified" (owner confirmed the
            // merchant account can actually accept these payments).
            $table->boolean('is_configured')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by')->nullable();
            $table->text('verification_notes')->nullable();
            $table->json('supported_currencies')->nullable();
            $table->string('public_client_id')->nullable();   // only ever public identifiers
            $table->json('settings')->nullable();             // non-secret settings only
            $table->unsignedInteger('position')->default(0);
            $table->text('capability_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('gateway_code');
            $table->string('mode', 16);                       // demo|sandbox|live
            $table->boolean('is_demo')->default(false);
            $table->string('status', 24)->default('pending');
            //   pending|requires_action|authorised|captured|failed|cancelled|refunded|partially_refunded
            $table->string('provider_order_id')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('idempotency_key')->nullable()->unique();

            // The amount actually charged, in the currency actually charged.
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            // If a disclosed conversion policy is in force these differ from the order.
            $table->bigInteger('presented_amount_minor')->nullable();
            $table->char('presented_currency', 3)->nullable();
            $table->decimal('fx_rate', 20, 10)->nullable();
            $table->timestamp('fx_rate_at')->nullable();
            $table->boolean('currency_disclosure_accepted')->default(false);

            $table->bigInteger('fee_minor')->nullable();
            $table->bigInteger('refunded_minor')->default(0);
            $table->timestamp('authorised_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->json('raw_payload')->nullable();          // redacted
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->unique(['gateway_code', 'provider_reference'], 'payments_provider_ref_unique');
        });

        // Every inbound gateway event, stored once, so duplicates and
        // out-of-order deliveries are detectable.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_code');
            $table->string('event_id');
            $table->string('event_type')->nullable();
            $table->boolean('signature_verified')->default(false);
            $table->string('verification_error')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamp('occurred_at')->nullable();     // provider timestamp, for ordering
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('status', 24)->default('received'); // received|processed|ignored|failed
            $table->text('note')->nullable();
            $table->json('payload')->nullable();              // redacted
            $table->timestamps();
            $table->unique(['gateway_code', 'event_id']);
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('provider_reference')->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            // A refund request is not a refund. Status makes that explicit.
            $table->string('status', 24)->default('requested');
            //   requested|approved|processing|completed|failed|rejected
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'provider_reference'], 'refunds_provider_ref_unique');
        });

        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('state', 32)->default('requested');
            //   requested|approved|rejected|awaiting_return|received|refunded|closed
            $table->string('reason');
            $table->text('customer_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->string('supplier_claim_reference')->nullable();
            $table->text('supplier_claim_notes')->nullable();
            $table->foreignId('refund_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('condition')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'return_request_items', 'return_requests', 'refunds',
            'payment_events', 'payments', 'payment_gateways',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
