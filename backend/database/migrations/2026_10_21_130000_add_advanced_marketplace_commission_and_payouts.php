<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_settings', function (Blueprint $table): void {
            $table->string('payout_schedule', 20)->default('manual')->after('commission_base');
            $table->decimal('payout_minimum', 18, 2)->default(0)->after('payout_schedule');
        });

        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->string('promotion_code', 100)->nullable()->after('order_number');
            $table->index(['promotion_code', 'created_at'], 'marketplace_orders_promotion_created_idx');
        });

        Schema::table('marketplace_commission_rules', function (Blueprint $table): void {
            $table->string('rule_type', 20)->default('business')->after('tenant_id');
            $table->foreignId('category_id')->nullable()->after('tenant_id')->constrained('categories')->restrictOnDelete();
            $table->string('promotion_code', 100)->nullable();
            $table->string('reason', 255)->nullable();
            $table->integer('priority')->default(0);
            $table->index(['rule_type', 'status', 'effective_from', 'effective_until'], 'marketplace_rules_type_status_dates_idx');
            $table->index(['category_id', 'status', 'effective_from'], 'marketplace_rules_category_status_idx');
        });

        DB::statement("UPDATE marketplace_commission_rules SET rule_type = 'global' WHERE tenant_id IS NULL AND category_id IS NULL AND rule_type = 'business'");
        DB::statement("ALTER TABLE marketplace_commission_rules ADD CONSTRAINT marketplace_commission_rules_type_check CHECK (rule_type IN ('global', 'business', 'category', 'promotional'))");
        DB::statement('ALTER TABLE marketplace_settings ADD CONSTRAINT marketplace_settings_payout_minimum_check CHECK (payout_minimum >= 0)');
        DB::statement("ALTER TABLE marketplace_settings ADD CONSTRAINT marketplace_settings_payout_schedule_check CHECK (payout_schedule IN ('manual', 'weekly', 'biweekly', 'monthly'))");

        Schema::create('marketplace_payouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('requested');
            $table->string('provider', 40)->nullable();
            $table->string('provider_reference', 180)->nullable();
            $table->string('idempotency_key', 180)->unique();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at')->useCurrent();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('processed_at')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('marketplace_payout_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_payout_id')->constrained('marketplace_payouts')->cascadeOnDelete();
            $table->foreignId('marketplace_settlement_entry_id')->unique()->constrained('marketplace_settlement_entries')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestampsTz();
            $table->index(['marketplace_payout_id', 'created_at']);
        });

        DB::statement('ALTER TABLE marketplace_payouts ADD CONSTRAINT marketplace_payouts_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE marketplace_payouts ADD CONSTRAINT marketplace_payouts_status_check CHECK (status IN ('requested', 'approved', 'processing', 'paid', 'rejected', 'failed', 'cancelled'))");
        DB::statement("ALTER TABLE marketplace_payouts ADD CONSTRAINT marketplace_payouts_currency_check CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE marketplace_payout_items ADD CONSTRAINT marketplace_payout_items_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE marketplace_payout_items DROP CONSTRAINT IF EXISTS marketplace_payout_items_amount_check');
        DB::statement('ALTER TABLE marketplace_payouts DROP CONSTRAINT IF EXISTS marketplace_payouts_currency_check');
        DB::statement('ALTER TABLE marketplace_payouts DROP CONSTRAINT IF EXISTS marketplace_payouts_status_check');
        DB::statement('ALTER TABLE marketplace_payouts DROP CONSTRAINT IF EXISTS marketplace_payouts_amount_check');
        Schema::dropIfExists('marketplace_payout_items');
        Schema::dropIfExists('marketplace_payouts');
        DB::statement('ALTER TABLE marketplace_settings DROP CONSTRAINT IF EXISTS marketplace_settings_payout_schedule_check');
        DB::statement('ALTER TABLE marketplace_settings DROP CONSTRAINT IF EXISTS marketplace_settings_payout_minimum_check');
        Schema::table('marketplace_settings', function (Blueprint $table): void {
            $table->dropColumn(['payout_schedule', 'payout_minimum']);
        });
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->dropIndex('marketplace_orders_promotion_created_idx');
            $table->dropColumn('promotion_code');
        });
        DB::statement('ALTER TABLE marketplace_commission_rules DROP CONSTRAINT IF EXISTS marketplace_commission_rules_type_check');
        Schema::table('marketplace_commission_rules', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
            $table->dropIndex('marketplace_rules_type_status_dates_idx');
            $table->dropIndex('marketplace_rules_category_status_idx');
            $table->dropColumn(['rule_type', 'category_id', 'promotion_code', 'reason', 'priority']);
        });
    }
};
