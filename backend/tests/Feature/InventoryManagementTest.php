<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_statistics_are_tenant_scoped_and_use_real_ledger_values(): void
    {
        [$tenantA, $adminA] = $this->business('INV-A', 'inventory-a@example.com');
        [$tenantB, $adminB] = $this->business('INV-B', 'inventory-b@example.com');
        $warehouse = $this->actingAs($adminA, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantA->uuid])->postJson('/api/v1/warehouses', ['name' => 'A Warehouse', 'code' => 'A-WH'])->assertCreated();

        $this->actingAs($adminA, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantA->uuid])->getJson('/api/v1/inventory/statistics')->assertOk()->assertJsonPath('data.total_stock_units', '0')->assertJsonPath('data.locations', 1);
        $this->actingAs($adminB, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantB->uuid])->getJson('/api/v1/inventory/statistics')->assertOk()->assertJsonPath('data.locations', 0);
        $this->actingAs($adminB, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantB->uuid])->getJson('/api/v1/inventory/locations/'.$warehouse->json('data.id'))->assertNotFound();
    }

    public function test_inventory_statistics_require_an_active_business_manager(): void
    {
        [$tenant] = $this->business('INV-AUTH', 'inventory-auth@example.com');
        $outsider = User::factory()->create(['email' => 'inventory-outsider@example.com']);
        $this->actingAs($outsider, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid])->getJson('/api/v1/inventory/statistics')->assertForbidden();
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
