<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\BusinessDeletionService;
use App\Services\BusinessLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_super_admin_cannot_preview_or_delete_a_business(): void
    {
        $tenant = Tenant::create(['name' => 'Unused', 'slug' => 'unused', 'business_code' => 'UNUSED', 'status' => 'pending']);
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/platform/businesses/'.$tenant->uuid.'/deletion-preview')->assertForbidden();
        $this->actingAs($user)->deleteJson('/api/v1/platform/businesses/'.$tenant->uuid, ['business_code' => 'UNUSED', 'preview_token' => str_repeat('a', 64), 'confirm' => true])->assertForbidden();
    }

    public function test_preview_blocks_financial_records_and_only_counts_the_selected_tenant(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $selected = Tenant::create(['name' => 'Selected', 'slug' => 'selected', 'business_code' => 'SELECTED', 'status' => 'pending']);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'business_code' => 'OTHER', 'status' => 'pending']);
        $store = DB::table('stores')->insertGetId(['tenant_id' => $selected->id, 'name' => 'Store', 'code' => 'STORE', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $register = DB::table('pos_registers')->insertGetId(['uuid' => Str::uuid(), 'tenant_id' => $selected->id, 'store_id' => $store, 'name' => 'Register', 'code' => 'REG', 'status' => 'active', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales')->insert(['uuid' => Str::uuid(), 'tenant_id' => $selected->id, 'store_id' => $store, 'register_id' => $register, 'cashier_user_id' => $admin->id, 'sale_number' => 'S-1', 'currency_code' => 'MMK', 'status' => 'completed', 'subtotal' => 1, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $preview = app(BusinessDeletionService::class)->preview($selected);
        $this->assertSame(1, $preview['blocking_records']['sales']);
        $this->assertSame(0, app(BusinessDeletionService::class)->preview($other)['blocking_records']['sales'] ?? 0);
    }

    public function test_shared_user_is_not_marked_for_account_deletion(): void
    {
        $selected = Tenant::create(['name' => 'Selected', 'slug' => 'selected-shared', 'business_code' => 'SHARED', 'status' => 'pending']);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-shared', 'business_code' => 'OTHER2', 'status' => 'pending']);
        $shared = User::factory()->create();
        DB::table('tenant_memberships')->insert([
            ['tenant_id' => $selected->id, 'user_id' => $shared->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $other->id, 'user_id' => $shared->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $preview = app(BusinessDeletionService::class)->preview($selected);
        $this->assertSame(0, $preview['deletable_user_count']);
    }

    public function test_super_admin_can_archive_and_restore_without_removing_data(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $tenant = Tenant::create(['name' => 'Archive me', 'slug' => 'archive-me', 'business_code' => 'ARCHIVE', 'status' => 'active']);
        DB::table('products')->insert(['uuid' => Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Keep me', 'slug' => 'keep-me', 'status' => 'active', 'track_inventory' => true, 'created_at' => now(), 'updated_at' => now()]);

        app(BusinessLifecycleService::class)->archive($tenant, $admin);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'status' => 'archived']);
        $this->assertDatabaseHas('products', ['tenant_id' => $tenant->id, 'slug' => 'keep-me']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'platform.business_archived']);

        app(BusinessLifecycleService::class)->restore($tenant->refresh(), $admin);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'status' => 'active']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'platform.business_restored']);
    }
}
