<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformBusinessControlCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_super_admin_can_read_the_business_control_center(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        [$tenant, $admin] = $this->business('CONTROL-A', 'control-admin@example.com');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/v1/platform/businesses/{$tenant->uuid}/control-center")
            ->assertOk()
            ->assertJsonPath('data.business.business_code', 'CONTROL-A')
            ->assertJsonPath('data.usage.branches', 0)
            ->assertJsonPath('data.subscription.data_available', false)
            ->assertJsonPath('data.capabilities.quota_enforcement', false)
            ->assertJsonMissingPath('data.admins.0.user.password');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/platform/businesses/{$tenant->uuid}/control-center")
            ->assertForbidden();
    }

    public function test_shared_user_remains_active_when_one_business_membership_is_revoked(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        [$tenantA] = $this->business('CONTROL-SHARED-A', 'shared-control@example.com');
        [$tenantB] = $this->business('CONTROL-SHARED-B', 'other-control@example.com');
        $shared = User::query()->where('email', 'shared-control@example.com')->firstOrFail();
        $roleB = Role::query()->where('tenant_id', $tenantB->id)->where('name', 'business_admin')->firstOrFail();
        TenantMembership::create(['tenant_id' => $tenantB->id, 'user_id' => $shared->id, 'role_id' => $roleB->id, 'status' => 'active', 'joined_at' => now()]);
        $secondAdmin = User::factory()->create(['email' => 'second-control@example.com']);
        $roleA = Role::query()->where('tenant_id', $tenantA->id)->where('name', 'business_admin')->firstOrFail();
        TenantMembership::create(['tenant_id' => $tenantA->id, 'user_id' => $secondAdmin->id, 'role_id' => $roleA->id, 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/v1/platform/businesses/{$tenantA->uuid}/admins/{$shared->id}")
            ->assertOk();

        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $tenantA->id, 'user_id' => $shared->id, 'status' => 'revoked']);
        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $tenantB->id, 'user_id' => $shared->id, 'status' => 'active']);
        $this->assertSame(User::STATUS_ACTIVE, $shared->refresh()->status);
    }

    public function test_last_active_business_admin_cannot_be_removed(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        [$tenant, $admin] = $this->business('CONTROL-LAST', 'last-control@example.com');

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/v1/platform/businesses/{$tenant->uuid}/admins/{$admin->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'status' => 'active']);
    }

    private function business(string $code, string $email): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'trial']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        $admin = User::factory()->create(['email' => $email, 'status' => User::STATUS_ACTIVE]);
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        return [$tenant, $admin, $membership];
    }
}
