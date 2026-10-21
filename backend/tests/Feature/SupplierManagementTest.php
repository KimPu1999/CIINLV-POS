<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_management_supports_extended_fields_and_statistics(): void
    {
        [$tenant, $admin] = $this->business('SUP-A', 'supplier-admin@example.com');
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $response = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/suppliers', [
            'supplier_code' => 'ACME-001',
            'name' => 'Acme Wholesale',
            'supplier_type' => 'Distributor',
            'contact_person' => 'Aung Aung',
            'default_currency' => 'MMK',
            'default_lead_time_days' => 7,
            'minimum_order_amount' => '100000.00',
        ])->assertCreated();

        $response->assertJsonPath('data.supplier_code', 'ACME-001')
            ->assertJsonPath('data.supplier_type', 'Distributor')
            ->assertJsonPath('data.default_lead_time_days', 7);

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/suppliers/statistics')
            ->assertOk()->assertJsonPath('data.total_suppliers', 1)->assertJsonPath('data.active_suppliers', 1)
            ->assertJsonPath('data.payables_available', false);

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->patchJson('/api/v1/suppliers/'.$response->json('data.id'), ['status' => 'archived'])
            ->assertOk()->assertJsonPath('data.status', 'archived');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->patchJson('/api/v1/suppliers/'.$response->json('data.id'), ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_suppliers_are_tenant_isolated_and_codes_are_unique_per_tenant(): void
    {
        [$tenantA, $adminA] = $this->business('SUP-ISO-A', 'supplier-a@example.com');
        [$tenantB, $adminB] = $this->business('SUP-ISO-B', 'supplier-b@example.com');
        $headersA = ['X-Tenant-Id' => $tenantA->uuid];
        $headersB = ['X-Tenant-Id' => $tenantB->uuid];

        $created = $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/suppliers', ['supplier_code' => 'SHARED-001', 'name' => 'Tenant A Supplier'])->assertCreated();
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/suppliers', ['supplier_code' => 'SHARED-001', 'name' => 'Duplicate'])->assertStatus(409);
        $this->actingAs($adminB, 'sanctum')->withHeaders($headersB)->postJson('/api/v1/suppliers', ['supplier_code' => 'SHARED-001', 'name' => 'Tenant B Supplier'])->assertCreated();

        $this->actingAs($adminB, 'sanctum')->withHeaders($headersB)->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Tenant B Supplier');
        $this->actingAs($adminB, 'sanctum')->withHeaders($headersB)->getJson('/api/v1/suppliers/'.$created->json('data.id'))->assertNotFound();
        $this->assertDatabaseHas('suppliers', ['tenant_id' => $tenantA->id, 'name' => 'Tenant A Supplier']);
        $this->assertDatabaseHas('suppliers', ['tenant_id' => $tenantB->id, 'name' => 'Tenant B Supplier']);
    }

    public function test_supplier_routes_require_a_business_manager_membership(): void
    {
        [$tenant] = $this->business('SUP-AUTH', 'supplier-auth@example.com');
        $user = User::factory()->create(['email' => 'supplier-outsider@example.com']);

        $this->actingAs($user, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid])->getJson('/api/v1/suppliers')->assertForbidden();
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
