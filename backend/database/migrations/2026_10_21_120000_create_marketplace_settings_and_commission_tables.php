<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 30)->default('marketplace')->unique();
            $table->boolean('enabled')->default(true);
            $table->boolean('seller_registration_enabled')->default(true);
            $table->boolean('seller_approval_required')->default(true);
            $table->boolean('listing_approval_required')->default(true);
            $table->boolean('maintenance_mode')->default(false);
            $table->string('currency_code', 3)->default('MMK');
            $table->decimal('default_commission_rate', 7, 4)->default(0);
            $table->string('commission_base', 30)->default('item_subtotal');
            $table->jsonb('policies')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('marketplace_commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('rate', 7, 4);
            $table->string('commission_base', 30)->default('item_subtotal');
            $table->string('status', 20)->default('active');
            $table->timestampTz('effective_from')->useCurrent();
            $table->timestampTz('effective_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'effective_from']);
            $table->index(['status', 'effective_from', 'effective_until']);
        });

        Schema::create('marketplace_order_commissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('marketplace_vendor_order_id')->unique()->constrained('marketplace_vendor_orders')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('marketplace_commission_rule_id')->nullable()->constrained('marketplace_commission_rules')->nullOnDelete();
            $table->string('currency_code', 3);
            $table->decimal('commission_base_amount', 18, 2)->default(0);
            $table->decimal('commission_rate', 7, 4)->default(0);
            $table->decimal('commission_amount', 18, 2)->default(0);
            $table->decimal('seller_net_amount', 18, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestampTz('calculated_at')->useCurrent();
            $table->timestampTz('settled_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['marketplace_order_id', 'status']);
        });

        Schema::create('marketplace_settlement_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_order_id')->nullable()->constrained('marketplace_orders')->nullOnDelete();
            $table->foreignId('marketplace_vendor_order_id')->nullable()->constrained('marketplace_vendor_orders')->nullOnDelete();
            $table->foreignId('marketplace_order_commission_id')->nullable()->constrained('marketplace_order_commissions')->nullOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('entry_type', 30);
            $table->string('direction', 10);
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('currency_code', 3);
            $table->string('status', 20)->default('pending');
            $table->string('idempotency_key', 180)->unique();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'entry_type']);
            $table->index(['marketplace_order_id', 'entry_type']);
        });

        DB::statement("ALTER TABLE marketplace_settings ADD CONSTRAINT marketplace_settings_currency_code_check CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE marketplace_settings ADD CONSTRAINT marketplace_settings_commission_rate_check CHECK (default_commission_rate >= 0 AND default_commission_rate <= 100)');
        DB::statement("ALTER TABLE marketplace_settings ADD CONSTRAINT marketplace_settings_commission_base_check CHECK (commission_base IN ('item_subtotal'))");
        DB::statement('ALTER TABLE marketplace_commission_rules ADD CONSTRAINT marketplace_commission_rules_rate_check CHECK (rate >= 0 AND rate <= 100)');
        DB::statement("ALTER TABLE marketplace_commission_rules ADD CONSTRAINT marketplace_commission_rules_base_check CHECK (commission_base IN ('item_subtotal'))");
        DB::statement("ALTER TABLE marketplace_commission_rules ADD CONSTRAINT marketplace_commission_rules_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE marketplace_order_commissions ADD CONSTRAINT marketplace_order_commissions_rate_check CHECK (commission_rate >= 0 AND commission_rate <= 100)');
        DB::statement('ALTER TABLE marketplace_order_commissions ADD CONSTRAINT marketplace_order_commissions_amounts_check CHECK (commission_base_amount >= 0 AND commission_amount >= 0 AND seller_net_amount >= 0)');
        DB::statement("ALTER TABLE marketplace_order_commissions ADD CONSTRAINT marketplace_order_commissions_status_check CHECK (status IN ('pending', 'payable', 'reversed'))");
        DB::statement("ALTER TABLE marketplace_settlement_entries ADD CONSTRAINT marketplace_settlement_entries_type_check CHECK (entry_type IN ('platform_commission', 'seller_earning', 'refund', 'payout', 'adjustment'))");
        DB::statement("ALTER TABLE marketplace_settlement_entries ADD CONSTRAINT marketplace_settlement_entries_direction_check CHECK (direction IN ('credit', 'debit'))");
        DB::statement("ALTER TABLE marketplace_settlement_entries ADD CONSTRAINT marketplace_settlement_entries_status_check CHECK (status IN ('pending', 'available', 'reversed'))");
        DB::statement('ALTER TABLE marketplace_settlement_entries ADD CONSTRAINT marketplace_settlement_entries_amount_check CHECK (amount >= 0)');
    }

    public function down(): void
    {
        foreach (['marketplace_settlement_entries', 'marketplace_order_commissions', 'marketplace_commission_rules', 'marketplace_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
