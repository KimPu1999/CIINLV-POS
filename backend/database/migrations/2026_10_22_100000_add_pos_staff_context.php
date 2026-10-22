<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE store_memberships DROP CONSTRAINT IF EXISTS store_memberships_role_check');
        DB::statement("ALTER TABLE store_memberships ADD CONSTRAINT store_memberships_role_check CHECK (role IS NULL OR role IN ('store_manager', 'inventory_manager', 'cashier', 'salesperson', 'sales_staff'))");

        Schema::table('cashier_shifts', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('status');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('cashier_shift_id')->nullable()->after('register_id');
            $table->foreignId('salesperson_user_id')->nullable()->after('cashier_user_id');
            $table->index(['tenant_id', 'cashier_shift_id']);
            $table->index(['tenant_id', 'salesperson_user_id', 'created_at']);
            $table->foreign(['tenant_id', 'cashier_shift_id'])
                ->references(['tenant_id', 'id'])->on('cashier_shifts')->nullOnDelete();
            $table->foreign('salesperson_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('register_id')->nullable()->after('store_id');
            $table->foreignId('cashier_shift_id')->nullable()->after('register_id');
            $table->decimal('received_amount', 18, 2)->nullable()->after('amount');
            $table->decimal('change_amount', 18, 2)->default(0)->after('received_amount');
            $table->index(['tenant_id', 'register_id']);
            $table->index(['tenant_id', 'cashier_shift_id']);
            $table->foreign('register_id')->references('id')->on('pos_registers')->nullOnDelete();
            $table->foreign('cashier_shift_id')->references('id')->on('cashier_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['register_id']);
            $table->dropForeign(['cashier_shift_id']);
            $table->dropIndex(['tenant_id', 'register_id']);
            $table->dropIndex(['tenant_id', 'cashier_shift_id']);
            $table->dropColumn(['register_id', 'cashier_shift_id', 'received_amount', 'change_amount']);
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'cashier_shift_id']);
            $table->dropForeign(['salesperson_user_id']);
            $table->dropIndex(['tenant_id', 'cashier_shift_id']);
            $table->dropIndex(['tenant_id', 'salesperson_user_id', 'created_at']);
            $table->dropColumn(['cashier_shift_id', 'salesperson_user_id']);
        });

        Schema::table('cashier_shifts', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });

        DB::statement('ALTER TABLE store_memberships DROP CONSTRAINT IF EXISTS store_memberships_role_check');
        DB::statement("ALTER TABLE store_memberships ADD CONSTRAINT store_memberships_role_check CHECK (role IS NULL OR role IN ('store_manager', 'inventory_manager', 'cashier'))");
    }
};
