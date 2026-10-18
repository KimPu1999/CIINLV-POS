<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 2 used this column for the original tenant-membership/store pivot.
        // Phase 4 stores direct user/role assignments, so the legacy link is optional.
        DB::statement('ALTER TABLE store_memberships ALTER COLUMN tenant_membership_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Existing Phase 4 direct assignments may not have a legacy pivot row; retain nullable compatibility on rollback.
    }
};
