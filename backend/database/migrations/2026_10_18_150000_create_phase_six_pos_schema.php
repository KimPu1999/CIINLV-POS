<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('status', 30)->default('active');
            $table->jsonb('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'pos_registers_tenant_id_id_unique');
            $table->unique(['tenant_id', 'store_id', 'code']);
            $table->index(['tenant_id', 'store_id', 'status']);
            $table->foreign(['tenant_id', 'store_id'])
                ->references(['tenant_id', 'id'])->on('stores')->cascadeOnDelete();
        });

        Schema::create('cashier_shifts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('register_id')->constrained('pos_registers')->cascadeOnDelete();
            $table->foreignId('cashier_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('opening_amount', 18, 2);
            $table->decimal('closing_amount', 18, 2)->nullable();
            $table->decimal('expected_amount', 18, 2)->default(0);
            $table->decimal('actual_amount', 18, 2)->nullable();
            $table->decimal('cash_difference', 18, 2)->nullable();
            $table->string('status', 20)->default('open');
            $table->timestampTz('opened_at')->useCurrent();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'cashier_shifts_tenant_id_id_unique');
            $table->index(['tenant_id', 'store_id', 'status']);
            $table->index(['tenant_id', 'cashier_user_id', 'status']);
            $table->foreign(['tenant_id', 'store_id'])
                ->references(['tenant_id', 'id'])->on('stores')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'register_id'])
                ->references(['tenant_id', 'id'])->on('pos_registers')->cascadeOnDelete();
        });

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('register_id')->constrained('pos_registers')->cascadeOnDelete();
            $table->foreignId('cashier_shift_id')->constrained()->cascadeOnDelete();
            $table->string('movement_type', 30);
            $table->decimal('amount', 18, 2);
            $table->string('reference_type', 100)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 150)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'cashier_shift_id', 'created_at']);
        });

        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('register_id')->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('cashier_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('sale_number', 80);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->decimal('paid_total', 18, 2)->default(0);
            $table->decimal('balance_due', 18, 2)->default(0);
            $table->decimal('change_due', 18, 2)->default(0);
            $table->decimal('refunded_total', 18, 2)->default(0);
            $table->string('currency_code', 3);
            $table->string('status', 30)->default('draft');
            $table->string('payment_status', 30)->default('unpaid');
            $table->string('idempotency_key', 150)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->timestampTz('held_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'sales_tenant_id_id_unique');
            $table->unique(['tenant_id', 'sale_number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'store_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'cashier_user_id', 'created_at']);
            $table->foreign(['tenant_id', 'store_id'])
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['tenant_id', 'register_id'])
                ->references(['tenant_id', 'id'])->on('pos_registers')->restrictOnDelete();
        });

        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot', 100);
            $table->string('barcode_snapshot', 100)->nullable();
            $table->string('unit_snapshot', 30)->nullable();
            $table->decimal('quantity', 18, 3);
            $table->decimal('returned_quantity', 18, 3)->default(0);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->decimal('unit_cost_at_sale', 18, 4)->default(0);
            $table->jsonb('tax_snapshot')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'sale_items_tenant_id_id_unique');
            $table->index(['tenant_id', 'sale_id']);
            $table->foreign(['tenant_id', 'sale_id'])
                ->references(['tenant_id', 'id'])->on('sales')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'])
                ->references(['tenant_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('sale_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label');
            $table->decimal('amount', 18, 2);
            $table->jsonb('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'sale_id'])
                ->references(['tenant_id', 'id'])->on('sales')->cascadeOnDelete();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('method', 30);
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 3);
            $table->string('status', 20)->default('pending');
            $table->string('external_reference', 150)->nullable();
            $table->string('provider', 80)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'provider', 'external_reference']);
            $table->index(['tenant_id', 'sale_id', 'status']);
            $table->foreign(['tenant_id', 'store_id'])
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sale_id'])
                ->references(['tenant_id', 'id'])->on('sales')->restrictOnDelete();
        });

        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('return_number', 80);
            $table->string('status', 20)->default('requested');
            $table->text('reason');
            $table->decimal('refund_total', 18, 2)->default(0);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64)->nullable();
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'sale_returns_tenant_id_id_unique');
            $table->unique(['tenant_id', 'return_number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'store_id', 'status', 'created_at']);
            $table->foreign(['tenant_id', 'store_id'])
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sale_id'])
                ->references(['tenant_id', 'id'])->on('sales')->restrictOnDelete();
        });

        Schema::create('sale_return_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_refund_amount', 18, 2);
            $table->decimal('refund_amount', 18, 2);
            $table->boolean('restock')->default(false);
            $table->string('disposition', 20)->default('damaged');
            $table->timestampsTz();
            $table->unique(['sale_return_id', 'sale_item_id']);
            $table->foreign(['tenant_id', 'sale_return_id'])
                ->references(['tenant_id', 'id'])->on('sale_returns')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sale_item_id'])
                ->references(['tenant_id', 'id'])->on('sale_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'])
                ->references(['tenant_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_return_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 30);
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 3);
            $table->string('status', 20)->default('pending');
            $table->string('external_reference', 150)->nullable();
            $table->string('provider', 80)->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'sale_id', 'status']);
            $table->foreign(['tenant_id', 'store_id'])
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sale_id'])
                ->references(['tenant_id', 'id'])->on('sales')->restrictOnDelete();
        });

        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->unique()->constrained()->restrictOnDelete();
            $table->string('receipt_number', 80);
            $table->string('format', 20)->default('80mm');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('generated_at')->useCurrent();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'receipt_number']);
            $table->foreign(['tenant_id', 'store_id'])
                ->references(['tenant_id', 'id'])->on('stores')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sale_id'])
                ->references(['tenant_id', 'id'])->on('sales')->restrictOnDelete();
        });

        DB::statement("CREATE UNIQUE INDEX cashier_shifts_open_register_unique ON cashier_shifts (tenant_id, register_id) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX cashier_shifts_open_cashier_unique ON cashier_shifts (tenant_id, cashier_user_id) WHERE status = 'open'");
        DB::statement("ALTER TABLE pos_registers ADD CONSTRAINT pos_registers_status_check CHECK (status IN ('active', 'inactive', 'suspended'))");
        DB::statement("ALTER TABLE cashier_shifts ADD CONSTRAINT cashier_shifts_status_check CHECK (status IN ('open', 'closed'))");
        DB::statement('ALTER TABLE cashier_shifts ADD CONSTRAINT cashier_shifts_amounts_check CHECK (opening_amount >= 0 AND (closing_amount IS NULL OR closing_amount >= 0) AND expected_amount >= 0 AND (actual_amount IS NULL OR actual_amount >= 0))');
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_type_check CHECK (movement_type IN ('opening_float', 'cash_sale', 'cash_refund', 'cash_in', 'cash_out'))");
        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_status_check CHECK (status IN ('draft', 'held', 'pending_payment', 'completed', 'cancelled', 'partially_refunded', 'refunded'))");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_payment_status_check CHECK (payment_status IN ('unpaid', 'pending', 'partially_paid', 'paid', 'partially_refunded', 'refunded', 'failed'))");
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_amounts_check CHECK (subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0 AND grand_total >= 0 AND paid_total >= 0 AND balance_due >= 0 AND change_due >= 0 AND refunded_total >= 0)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_quantity_check CHECK (quantity > 0 AND returned_quantity >= 0 AND returned_quantity <= quantity AND unit_price >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_total >= 0 AND unit_cost_at_sale >= 0)');
        DB::statement("ALTER TABLE sale_adjustments ADD CONSTRAINT sale_adjustments_type_check CHECK (type IN ('discount', 'tax', 'surcharge'))");
        DB::statement('ALTER TABLE sale_adjustments ADD CONSTRAINT sale_adjustments_amount_check CHECK (amount >= 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('cash', 'card_terminal', 'qr', 'bank_transfer'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'verified', 'failed', 'refunded'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE sale_returns ADD CONSTRAINT sale_returns_status_check CHECK (status IN ('requested', 'approved', 'rejected', 'refunded', 'cancelled'))");
        DB::statement('ALTER TABLE sale_returns ADD CONSTRAINT sale_returns_amount_check CHECK (refund_total >= 0)');
        DB::statement("ALTER TABLE sale_return_items ADD CONSTRAINT sale_return_items_disposition_check CHECK (disposition IN ('sellable', 'damaged', 'expired', 'destroyed'))");
        DB::statement('ALTER TABLE sale_return_items ADD CONSTRAINT sale_return_items_quantity_check CHECK (quantity > 0 AND unit_refund_amount >= 0 AND refund_amount >= 0)');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_method_check CHECK (method IN ('cash', 'card_terminal', 'qr', 'bank_transfer'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('pending', 'processing', 'succeeded', 'failed'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_format_check CHECK (format IN ('58mm', '80mm', 'a4'))");
    }

    public function down(): void
    {
        foreach (['receipts', 'refunds', 'sale_return_items', 'sale_returns', 'payments', 'sale_adjustments', 'sale_items', 'sales', 'cash_movements', 'cashier_shifts', 'pos_registers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
