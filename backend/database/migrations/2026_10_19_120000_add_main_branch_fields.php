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
            $table->string('region')->nullable()->after('city');
            $table->boolean('is_main')->default(false)->after('status');
        });

        // Deterministically designate the oldest existing branch only for businesses
        // that do not already have a main branch. No historical transactions change.
        DB::statement(<<<'SQL'
            WITH first_store AS (
                SELECT DISTINCT ON (tenant_id) id, tenant_id
                FROM stores
                WHERE deleted_at IS NULL
                ORDER BY tenant_id, id
            )
            UPDATE stores
            SET is_main = TRUE
            FROM first_store
            WHERE stores.id = first_store.id
              AND NOT EXISTS (
                  SELECT 1 FROM stores existing_main
                  WHERE existing_main.tenant_id = stores.tenant_id
                    AND existing_main.is_main = TRUE
                    AND existing_main.deleted_at IS NULL
              )
        SQL);

        DB::statement("CREATE UNIQUE INDEX stores_one_main_per_tenant_unique ON stores (tenant_id) WHERE is_main = TRUE AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stores_one_main_per_tenant_unique');
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['region', 'is_main']);
        });
    }
};
