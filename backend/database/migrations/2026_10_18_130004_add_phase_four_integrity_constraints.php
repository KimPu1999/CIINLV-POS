<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_status_phase_four_check CHECK (status IN ('active', 'inactive', 'suspended'))");
        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_currency_code_check CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE store_settings ADD CONSTRAINT store_settings_json_size_check CHECK (COALESCE(pg_column_size(tax_configuration), 0) <= 65536 AND COALESCE(pg_column_size(receipt_configuration), 0) <= 65536 AND COALESCE(pg_column_size(dashboard_preferences), 0) <= 65536)');
        DB::statement('ALTER TABLE dashboard_layouts ADD CONSTRAINT dashboard_layouts_json_size_check CHECK (pg_column_size(layout) <= 65536)');
        DB::statement('ALTER TABLE store_memberships ADD CONSTRAINT store_memberships_assignment_fields_check CHECK ((tenant_id IS NULL AND user_id IS NULL AND role IS NULL) OR (tenant_id IS NOT NULL AND user_id IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE store_memberships DROP CONSTRAINT IF EXISTS store_memberships_assignment_fields_check');
        DB::statement('ALTER TABLE dashboard_layouts DROP CONSTRAINT IF EXISTS dashboard_layouts_json_size_check');
        DB::statement('ALTER TABLE store_settings DROP CONSTRAINT IF EXISTS store_settings_json_size_check');
        DB::statement('ALTER TABLE stores DROP CONSTRAINT IF EXISTS stores_currency_code_check');
        DB::statement('ALTER TABLE stores DROP CONSTRAINT IF EXISTS stores_status_phase_four_check');
    }
};
