<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_funds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('fund_type', 30)->default('petty_cash');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('approval_limit', 18, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'store_id', 'status']);
        });

        Schema::create('cash_management_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained('cash_funds')->nullOnDelete();
            $table->foreignId('register_id')->nullable()->constrained('pos_registers')->nullOnDelete();
            $table->foreignId('cashier_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entry_type', 30);
            $table->string('direction', 10);
            $table->decimal('amount', 18, 2);
            $table->string('category', 80)->nullable();
            $table->text('reason')->nullable();
            $table->string('reference', 150)->nullable();
            $table->uuid('transfer_group_uuid')->nullable();
            $table->string('approval_status', 20)->default('approved');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('reversed_entry_id')->nullable()->constrained('cash_management_entries')->nullOnDelete();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->string('idempotency_key', 150)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'fund_id', 'approval_status', 'created_at']);
            $table->index(['tenant_id', 'register_id', 'approval_status', 'created_at']);
            $table->index(['tenant_id', 'transfer_group_uuid']);
        });

        Schema::create('cash_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained('cash_funds')->nullOnDelete();
            $table->foreignId('register_id')->nullable()->constrained('pos_registers')->nullOnDelete();
            $table->foreignId('cashier_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('expected_amount', 18, 2);
            $table->decimal('actual_amount', 18, 2);
            $table->decimal('variance', 18, 2);
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('idempotency_key', 150)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        DB::statement("ALTER TABLE cash_funds ADD CONSTRAINT cash_funds_type_check CHECK (fund_type IN ('petty_cash'))");
        DB::statement("ALTER TABLE cash_funds ADD CONSTRAINT cash_funds_amounts_check CHECK (opening_balance >= 0 AND approval_limit >= 0)");
        DB::statement("ALTER TABLE cash_funds ADD CONSTRAINT cash_funds_status_check CHECK (status IN ('active', 'closed'))");
        DB::statement("ALTER TABLE cash_management_entries ADD CONSTRAINT cash_management_entries_type_check CHECK (entry_type IN ('cash_in', 'cash_out', 'petty_expense', 'transfer', 'reversal'))");
        DB::statement("ALTER TABLE cash_management_entries ADD CONSTRAINT cash_management_entries_direction_check CHECK (direction IN ('credit', 'debit'))");
        DB::statement("ALTER TABLE cash_management_entries ADD CONSTRAINT cash_management_entries_amount_check CHECK (amount > 0)");
        DB::statement("ALTER TABLE cash_management_entries ADD CONSTRAINT cash_management_entries_source_check CHECK ((fund_id IS NOT NULL AND register_id IS NULL) OR (fund_id IS NULL AND register_id IS NOT NULL))");
        DB::statement("ALTER TABLE cash_management_entries ADD CONSTRAINT cash_management_entries_status_check CHECK (approval_status IN ('pending', 'approved', 'rejected', 'reversed'))");
        DB::statement("ALTER TABLE cash_reconciliations ADD CONSTRAINT cash_reconciliations_source_check CHECK ((fund_id IS NOT NULL AND register_id IS NULL) OR (fund_id IS NULL AND register_id IS NOT NULL))");
        DB::statement("ALTER TABLE cash_reconciliations ADD CONSTRAINT cash_reconciliations_amount_check CHECK (expected_amount >= 0 AND actual_amount >= 0)");
        DB::statement("ALTER TABLE cash_reconciliations ADD CONSTRAINT cash_reconciliations_status_check CHECK (status IN ('matched', 'variance'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_reconciliations');
        Schema::dropIfExists('cash_management_entries');
        Schema::dropIfExists('cash_funds');
    }
};
