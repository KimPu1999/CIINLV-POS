<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('stores')->select('id', 'tenant_id', 'name', 'code')->get() as $store) {
            if (DB::table('inventory_locations')->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->exists()) {
                continue;
            }
            DB::table('inventory_locations')->insert([
                'uuid' => (string) Str::uuid(), 'tenant_id' => $store->tenant_id, 'location_type' => 'store', 'store_id' => $store->id,
                'name' => $store->name.' Stockroom', 'code' => $store->code, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
