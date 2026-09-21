<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();               // internal reference
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_guest')->default(true);
            $table->string('email');
            $table->string('phone')->nullable();

            // FIVE INDEPENDENT STATE MACHINES.
            // "Customer paid" never implies "supplier ordered" or "supplier paid".
            $table->string('payment_state', 32)->default('pending');
            //   pending|authorised|paid|partially_refunded|refunded|failed|cancelled
            $table->string('approval_state', 32)->default('not_required');
            //   not_required|awaiting_approval|approved|rejected|on_hold
            $table->string('supplier_order_state', 32)->default('not_submitted');
            //   not_submitted|submitting|submitted|confirmed|rejected|cancelled|reconciling
            $table->string('supplier_payment_state', 32)->default('not_paid');
            //   not_paid|authorising|paid|failed|insufficient_balance|refunded
            $table->string('shipment_state', 32)->default('none');
            //   none|partially_shipped|shipped|partially_delivered|delivered|returned
            $table->string('lifecycle_status', 32)->default('new'); // derived, for display/reporting

            // Money: integer minor units, explicit currency, snapshotted at order time.
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('shipping_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('refunded_minor')->default(0);

            // Cost side, kept strictly separate from what the customer paid.
            $table->bigInteger('merchandise_cost_minor')->nullable();
            $table->bigInteger('supplier_shipping_cost_minor')->nullable();
            $table->bigInteger('packaging_cost_minor')->nullable();
            $table->bigInteger('gateway_fee_estimate_minor')->nullable();
            $table->bigInteger('gateway_fee_actual_minor')->nullable();
            $table->boolean('costs_reconciled')->default(false);

            $table->json('billing_address')->nullable();
            $table->json('shipping_address');
            // Historical snapshots are preserved even when branding later changes.
            $table->json('brand_snapshot')->nullable();
            $table->json('business_snapshot')->nullable();
            $table->json('totals_breakdown')->nullable();

            $table->string('fulfilment_mode', 32)->default('domestic_only'); // domestic_only|overseas_allowed
            $table->string('packaging_choice', 32)->default('standard');
            $table->foreignId('packaging_record_id')->nullable()->constrained()->nullOnDelete();

            // Demo isolation. Demo orders never enter production revenue reports.
            $table->boolean('is_demo')->default(false);
            $table->string('demo_reason')->nullable();

            $table->text('hold_reason')->nullable();
            $table->text('customer_note')->nullable();
            $table->text('admin_note')->nullable();

            // Guest access uses a hashed high-entropy token, never the order number.
            $table->string('access_token_hash', 64)->nullable()->index();
            $table->timestamp('access_token_expires_at')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('approval_authorised_supplier_charge')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['payment_state', 'approval_state']);
            $table->index(['is_demo', 'created_at']);
            $table->index('email');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->string('option_summary')->nullable();
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('line_subtotal_minor');
            $table->bigInteger('line_discount_minor')->default(0);
            $table->bigInteger('line_tax_minor')->default(0);
            $table->char('currency', 3);

            $table->string('supplier_variant_id')->nullable();
            $table->bigInteger('supplier_cost_minor')->nullable();
            $table->char('supplier_cost_currency', 3)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('quantity_fulfilled')->default(0);
            $table->unsignedInteger('quantity_refunded')->default(0);
            $table->unsignedInteger('quantity_returned')->default(0);

            $table->json('snapshot')->nullable();
            $table->timestamps();
        });

        // Append-only audit of every state machine transition.
        Schema::create('order_state_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('machine', 32);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->string('actor_type', 24)->default('system'); // system|admin|customer|webhook
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['order_id', 'machine']);
        });

        Schema::create('fulfilments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            // Our own reference, sent to the supplier so a timeout can be reconciled.
            $table->string('internal_reference')->unique();
            // Guarantees we never create two supplier orders for the same intent.
            $table->string('idempotency_key')->unique();
            $table->string('supplier_order_id')->nullable()->unique();
            $table->string('supplier_order_number')->nullable();
            $table->string('state', 32)->default('pending');
            //   pending|submitting|submitted|confirmed|rejected|cancelled|needs_reconciliation
            $table->string('mode', 16)->default('demo');      // demo|sandbox|live
            $table->boolean('is_demo')->default(false);

            $table->string('packaging_type', 32)->default('standard');
            $table->foreignId('packaging_record_id')->nullable()->constrained()->nullOnDelete();

            $table->bigInteger('merchandise_cost_minor')->nullable();
            $table->bigInteger('shipping_cost_minor')->nullable();
            $table->bigInteger('packaging_cost_minor')->nullable();
            $table->char('currency', 3)->default('USD');

            $table->json('request_payload')->nullable();      // redacted
            $table->json('response_payload')->nullable();     // redacted
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'state']);
        });

        Schema::create('fulfilment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fulfilment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['fulfilment_id', 'order_item_id']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fulfilment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('provider_reference')->nullable()->unique();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('USD');
            $table->string('status', 24)->default('pending');
            //   pending|authorised|paid|failed|insufficient_balance|refunded
            $table->string('mode', 16)->default('demo');
            $table->boolean('is_demo')->default(false);
            // Supplier payment is a separate, separately authorised financial process.
            $table->foreignId('authorised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorised_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fulfilment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('carrier')->nullable();
            $table->string('service')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url', 1024)->nullable();
            $table->string('state', 32)->default('pending');
            //   pending|in_transit|out_for_delivery|delivered|exception|returned
            $table->unsignedInteger('estimate_min')->nullable();
            $table->unsignedInteger('estimate_max')->nullable();
            $table->string('estimate_unit', 24)->default('unknown');
            $table->string('estimate_type', 24)->default('unknown');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            // Delivery is only claimed when the carrier/supplier actually evidences it.
            $table->string('delivery_evidence')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->json('raw_payload')->nullable();
            $table->timestamp('tracking_checked_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'state']);
            $table->index('tracking_number');
        });

        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unique(['shipment_id', 'order_item_id']);
        });

        Schema::table('discount_redemptions', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('discount_redemptions', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });
        foreach ([
            'shipment_items', 'shipments', 'supplier_payments', 'fulfilment_items',
            'fulfilments', 'order_state_events', 'order_items', 'orders',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
