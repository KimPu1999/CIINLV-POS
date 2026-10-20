<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_shipments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_vendor_order_id')->unique()->constrained('marketplace_vendor_orders')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('delivery_type', 30);
            $table->string('carrier_name')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->string('internal_tracking_id', 80)->unique()->nullable();
            $table->string('status', 30)->default('ready')->index();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tracking_number', 'carrier_name']);
        });

        Schema::create('marketplace_shipment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_shipment_id')->constrained('marketplace_shipments')->cascadeOnDelete();
            $table->string('status', 30);
            $table->text('description')->nullable();
            $table->timestampTz('event_time');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['marketplace_shipment_id', 'event_time']);
        });

        Schema::create('marketplace_cancellation_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->restrictOnDelete();
            $table->foreignId('customer_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unique(['marketplace_order_id', 'customer_user_id'], 'marketplace_cancel_order_customer_unique');
            $table->index(['customer_user_id', 'status']);
        });

        Schema::create('marketplace_return_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->restrictOnDelete();
            $table->foreignId('customer_user_id')->constrained('users')->restrictOnDelete();
            $table->jsonb('requested_items');
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unique(['marketplace_order_id', 'customer_user_id'], 'marketplace_return_order_customer_unique');
            $table->index(['customer_user_id', 'status']);
        });

        Schema::create('marketplace_refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->restrictOnDelete();
            $table->foreignId('customer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('marketplace_return_request_id')->nullable()->constrained('marketplace_return_requests')->nullOnDelete();
            $table->decimal('requested_amount', 18, 2)->nullable();
            $table->decimal('approved_amount', 18, 2)->nullable();
            $table->string('currency_code', 3);
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->string('provider', 80)->nullable();
            $table->string('provider_reference', 150)->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['marketplace_order_id', 'customer_user_id'], 'marketplace_refund_order_customer_unique');
            $table->unique(['provider', 'provider_reference'], 'marketplace_refund_provider_reference_unique');
            $table->index(['customer_user_id', 'status']);
        });

        DB::statement("ALTER TABLE marketplace_shipments ADD CONSTRAINT marketplace_shipments_delivery_type_check CHECK (delivery_type IN ('self_delivery', 'external_courier'))");
        DB::statement("ALTER TABLE marketplace_shipments ADD CONSTRAINT marketplace_shipments_status_check CHECK (status IN ('ready', 'shipped', 'in_transit', 'delivered', 'delivery_failed', 'returned', 'cancelled'))");
        DB::statement("ALTER TABLE marketplace_shipment_events ADD CONSTRAINT marketplace_shipment_events_status_check CHECK (status IN ('ready', 'shipped', 'in_transit', 'delivered', 'delivery_failed', 'returned', 'cancelled'))");
        DB::statement("ALTER TABLE marketplace_cancellation_requests ADD CONSTRAINT marketplace_cancellation_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
        DB::statement("ALTER TABLE marketplace_return_requests ADD CONSTRAINT marketplace_return_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
        DB::statement("ALTER TABLE marketplace_refund_requests ADD CONSTRAINT marketplace_refund_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'blocked'))");
    }

    public function down(): void
    {
        foreach (['marketplace_refund_requests', 'marketplace_return_requests', 'marketplace_cancellation_requests', 'marketplace_shipment_events', 'marketplace_shipments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
