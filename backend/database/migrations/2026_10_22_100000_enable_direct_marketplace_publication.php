<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketplace_settings')) {
            DB::table('marketplace_settings')->update([
                'seller_approval_required' => false,
                'listing_approval_required' => false,
            ]);
        }

        // Pending stores were created under the old approval-gated flow. Active
        // tenants are now publishable by their own business administrators;
        // retain inactive/suspended tenant visibility rules separately.
        if (Schema::hasTable('marketplace_stores') && Schema::hasTable('tenants')) {
            DB::table('marketplace_stores')
                ->where('approval_status', 'pending')
                ->where('is_active', true)
                ->whereIn('tenant_id', DB::table('tenants')->where('status', 'active')->pluck('id'))
                ->update(['approval_status' => 'approved', 'approved_at' => now()]);
        }
    }

    public function down(): void
    {
        // The previous approval state is not recoverable without a snapshot.
    }
};
