<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseFourStoreManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_create_and_list_multiple_branches(): void
    {
        [$tenant, $admin] = $this->business('BUS-A', 'admin-a@example.com', 'business_admin');
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $first = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores', [
            'name' => 'Dashboard 1 - Main Store', 'store_code' => 'MAIN-001', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon',
        ])->assertCreated();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores', [
            'name' => 'Dashboard 2 - Second Branch', 'store_code' => 'BRANCH-002',
        ])->assertCreated();

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/stores')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('stores', ['uuid' => $first->json('data.id'), 'code' => 'MAIN-001', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.store_created']);
    }

    public function test_branches_are_isolated_and_codes_are_only_unique_inside_a_business(): void
    {
        [$tenantA, $adminA] = $this->business('BUS-A', 'admin-a@example.com', 'business_admin');
        [$tenantB, $adminB] = $this->business('BUS-B', 'admin-b@example.com', 'business_admin');
        $payload = ['name' => 'Main', 'store_code' => 'MAIN-001'];

        $created = $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->postJson('/api/v1/business/stores', $payload)->assertCreated();
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->postJson('/api/v1/business/stores', $payload)->assertStatus(409);
        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->postJson('/api/v1/business/stores', $payload)->assertCreated();
        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/business/stores/'.$created->json('data.id'))->assertNotFound();
    }

    public function test_assigned_manager_can_access_only_assigned_branch_and_cross_tenant_assignment_is_rejected(): void
    {
        [$tenantA, $adminA] = $this->business('BUS-A', 'admin-a@example.com', 'business_admin');
        [$tenantB] = $this->business('BUS-B', 'admin-b@example.com', 'business_admin');
        $storeA = $this->createStore($tenantA, $adminA, 'A-001');
        $storeB = $this->createStore($tenantA, $adminA, 'A-002');
        $manager = $this->userWithMembership($tenantA, 'manager@example.com', 'store_manager');
        $foreign = $this->userWithMembership($tenantB, 'foreign@example.com', 'store_manager');
        $headers = ['X-Tenant-Id' => $tenantA->uuid];

        $this->actingAs($adminA, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores/'.$storeA->uuid.'/members', ['user_id' => $manager->id, 'role' => 'store_manager'])->assertCreated();
        $this->actingAs($adminA, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores/'.$storeA->uuid.'/members', ['user_id' => $foreign->id, 'role' => 'cashier'])->assertUnprocessable();
        $this->actingAs($manager, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/stores/'.$storeA->uuid)->assertOk();
        $this->actingAs($manager, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/stores/'.$storeB->uuid)->assertForbidden();
        $this->actingAs($manager, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores', ['name' => 'Nope', 'store_code' => 'NOPE'])->assertForbidden();
    }

    public function test_cashier_cannot_create_branch_and_revoked_or_suspended_access_is_blocked(): void
    {
        [$tenant, $admin] = $this->business('BUS-A', 'admin-a@example.com', 'business_admin');
        $store = $this->createStore($tenant, $admin, 'A-001');
        $cashier = $this->userWithMembership($tenant, 'cashier@example.com', 'cashier');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores/'.$store->uuid.'/members', ['user_id' => $cashier->id, 'role' => 'cashier'])->assertCreated();
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores', ['name' => 'Nope', 'store_code' => 'NOPE'])->assertForbidden();
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/stores/'.$store->uuid.'/dashboard')->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores/'.$store->uuid.'/suspend')->assertOk();
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/stores/'.$store->uuid.'/dashboard')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores/'.$store->uuid.'/activate')->assertOk();
        $membership = StoreMembership::where('store_id', $store->id)->where('user_id', $cashier->id)->firstOrFail();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->deleteJson('/api/v1/business/stores/'.$store->uuid.'/members/'.$membership->id)->assertOk();
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/stores/'.$store->uuid.'/dashboard')->assertForbidden();
    }

    public function test_dashboard_layout_and_permission_aware_widgets_work(): void
    {
        [$tenant, $admin] = $this->business('BUS-A', 'admin-a@example.com', 'business_admin');
        $store = $this->createStore($tenant, $admin, 'A-001');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $layout = ['version' => 1, 'widgets' => [['widget_key' => 'sales_overview', 'position' => 0, 'width' => 2, 'height' => 1]]];

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->putJson('/api/v1/business/stores/'.$store->uuid.'/dashboard/layout', ['layout' => $layout])->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/stores/'.$store->uuid.'/dashboard/layout')->assertOk()->assertJsonPath('data.layout.version', 1);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->putJson('/api/v1/business/stores/'.$store->uuid.'/dashboard/layout', ['layout' => ['widgets' => 'malformed']])->assertUnprocessable();

        $cashier = $this->userWithMembership($tenant, 'cashier@example.com', 'cashier');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores/'.$store->uuid.'/members', ['user_id' => $cashier->id, 'role' => 'cashier'])->assertCreated();
        $response = $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/stores/'.$store->uuid.'/dashboard')->assertOk();
        $keys = collect($response->json('data.widgets'))->pluck('widget_key')->all();
        $this->assertContains('sales_overview', $keys);
        $this->assertNotContains('supplier_overview', $keys);
        $this->assertSame('available', $response->json('data.metrics_status'));
        $this->assertSame('0.00', $response->json('data.metrics.sales_overview.today'));
    }

    public function test_branch_limit_is_enforced_for_trial_businesses(): void
    {
        [$tenant, $admin] = $this->business('BUS-A', 'admin-a@example.com', 'business_admin');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        for ($i = 1; $i <= 4; $i++) {
            $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores', ['name' => 'Branch '.$i, 'store_code' => 'BR-'.$i])->assertCreated();
        }
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores', ['name' => 'Branch 5', 'store_code' => 'BR-5'])->assertUnprocessable();
    }

    public function test_main_branch_switch_is_transactional_and_unique_per_business(): void
    {
        [$tenant, $admin] = $this->business('BUS-A', 'admin-a@example.com', 'business_admin');
        $first = $this->createStore($tenant, $admin, 'A-001');
        $second = $this->createStore($tenant, $admin, 'A-002');
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $this->assertTrue($first->fresh()->is_main);
        $this->assertFalse($second->fresh()->is_main);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/stores/'.$second->uuid.'/set-main')->assertOk()->assertJsonPath('data.is_main', true);
        $this->assertFalse($first->fresh()->is_main);
        $this->assertTrue($second->fresh()->is_main);
        $this->assertSame(1, Store::where('tenant_id', $tenant->id)->where('is_main', true)->count());
    }

    private function business(string $code, string $email, string $roleName): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'trial']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => $roleName]);
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        return [$tenant, $user];
    }

    private function userWithMembership(Tenant $tenant, string $email, string $roleName): User
    {
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $roleName]);
        $user = User::factory()->create(['email' => $email]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        return $user;
    }

    private function createStore(Tenant $tenant, User $admin, string $code): Store
    {
        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/business/stores', ['name' => $code, 'store_code' => $code]);
        $response->assertCreated();

        return Store::where('uuid', $response->json('data.id'))->firstOrFail();
    }
}
