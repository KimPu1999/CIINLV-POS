<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WarehouseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_crud_stats_and_archive_restore_are_tenant_scoped(): void
    {
        [$tenantA, $adminA] = $this->business('WH-A', 'warehouse-a@example.com');
        [$tenantB, $adminB] = $this->business('WH-B', 'warehouse-b@example.com');
        $headersA = ['X-Tenant-Id' => $tenantA->uuid];
        $headersB = ['X-Tenant-Id' => $tenantB->uuid];

        $created = $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/warehouses', ['name' => 'Main Warehouse', 'code' => 'MAIN-WH', 'warehouse_type' => 'distribution'])->assertCreated();
        $created->assertJsonPath('data.code', 'MAIN-WH')->assertJsonPath('data.warehouse_type', 'distribution');
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/warehouses/statistics')->assertOk()->assertJsonPath('data.total_warehouses', 1);
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->patchJson('/api/v1/warehouses/'.$created->json('data.id'), ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->patchJson('/api/v1/warehouses/'.$created->json('data.id'), ['status' => 'active'])->assertOk();
        $this->actingAs($adminB, 'sanctum')->withHeaders($headersB)->getJson('/api/v1/warehouses/'.$created->json('data.id'))->assertNotFound();
        $this->actingAs($adminB, 'sanctum')->withHeaders($headersB)->getJson('/api/v1/warehouses')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_warehouse_routes_require_business_manager_access(): void
    {
        [$tenant] = $this->business('WH-AUTH', 'warehouse-auth@example.com');
        $outsider = User::factory()->create(['email' => 'warehouse-outsider@example.com']);
        $this->actingAs($outsider, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid])->getJson('/api/v1/warehouses')->assertForbidden();
    }

    private function business(string $code, string $email): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        return [$tenant, $user];
    }
}
