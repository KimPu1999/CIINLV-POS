<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('pending', 'active', 'suspended'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_subscription_status_check CHECK (subscription_status IN ('trial', 'active', 'past_due', 'cancelled'))");
        DB::statement("ALTER TABLE tenant_memberships ADD CONSTRAINT tenant_memberships_status_check CHECK (status IN ('pending', 'active', 'revoked', 'suspended'))");
        DB::statement("ALTER TABLE business_settings ADD CONSTRAINT business_settings_currency_code_check CHECK (currency_code ~ '^[A-Z]{3}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE business_settings DROP CONSTRAINT IF EXISTS business_settings_currency_code_check');
        DB::statement('ALTER TABLE tenant_memberships DROP CONSTRAINT IF EXISTS tenant_memberships_status_check');
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_subscription_status_check');
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_status_check');
    }
};
