<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('currency_code', 3)->default('MMK');
            $table->string('timezone')->default('Asia/Yangon');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'id'], 'stores_tenant_id_id_unique');
        });

        Schema::table('store_memberships', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->after('store_id')->constrained()->cascadeOnDelete();
            $table->string('role')->nullable()->after('user_id');
            $table->foreignId('assigned_by')->nullable()->after('role')->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at')->nullable()->after('status');
            $table->timestampTz('revoked_at')->nullable()->after('assigned_at');
        });

        DB::statement(<<<'SQL'
            UPDATE store_memberships sm
            SET tenant_id = s.tenant_id,
                user_id = tm.user_id,
                role = CASE WHEN r.name IN ('store_manager', 'inventory_manager', 'cashier') THEN r.name ELSE NULL END,
                assigned_at = sm.created_at
            FROM stores s,
                 tenant_memberships tm
            LEFT JOIN roles r ON r.id = tm.role_id
            WHERE tm.id = sm.tenant_membership_id
              AND sm.store_id = s.id
              AND (sm.tenant_id IS NULL OR sm.user_id IS NULL OR sm.role IS NULL)
        SQL);

        DB::statement("CREATE UNIQUE INDEX store_memberships_active_user_store_unique ON store_memberships (store_id, user_id) WHERE status = 'active' AND revoked_at IS NULL");
        DB::statement("ALTER TABLE store_memberships ADD CONSTRAINT store_memberships_role_check CHECK (role IS NULL OR role IN ('store_manager', 'inventory_manager', 'cashier'))");
        DB::statement("ALTER TABLE store_memberships ADD CONSTRAINT store_memberships_status_phase_four_check CHECK (status IN ('active', 'suspended', 'revoked'))");
        DB::statement('ALTER TABLE store_memberships ADD CONSTRAINT store_memberships_tenant_store_fk FOREIGN KEY (tenant_id, store_id) REFERENCES stores (tenant_id, id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE store_memberships DROP CONSTRAINT IF EXISTS store_memberships_tenant_store_fk');
        DB::statement('ALTER TABLE store_memberships DROP CONSTRAINT IF EXISTS store_memberships_status_phase_four_check');
        DB::statement('ALTER TABLE store_memberships DROP CONSTRAINT IF EXISTS store_memberships_role_check');
        DB::statement('DROP INDEX IF EXISTS store_memberships_active_user_store_unique');

        Schema::table('store_memberships', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['role', 'assigned_at', 'revoked_at']);
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropUnique('stores_tenant_id_id_unique');
            $table->dropConstrainedForeignId('created_by');
            $table->dropSoftDeletesTz();
            $table->dropColumn(['description', 'email', 'phone', 'address', 'city', 'country', 'currency_code', 'timezone']);
        });
    }
};
