<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WorkspaceAccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_members_keep_both_access_and_admin_can_assign_a_tenant_scoped_mode(): void
    {
        [$tenant, $admin, $staff] = $this->fixture('workspace-access');
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $initial = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/workspace-access')
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'both')
            ->assertJsonPath('data.can_manage', true);
        $this->assertSame('both', collect($initial->json('data.members'))->firstWhere('user_id', $staff->id)['access_mode']);

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->patchJson('/api/v1/business/workspace-access/users/'.$staff->id, ['access_mode' => 'business_only'])
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'business_only');

        $this->assertDatabaseHas('workspace_access_controls', ['tenant_id' => $tenant->id, 'user_id' => $staff->id, 'access_mode' => 'business_only', 'preferred_view' => 'business']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'workspace.access_updated', 'actor_user_id' => $admin->id]);
    }

    public function test_personal_view_is_limited_to_granted_workspaces_and_persists(): void
    {
        [$tenant, $admin] = $this->fixture('workspace-preference');
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->patchJson('/api/v1/business/workspace-access/preference', ['preferred_view' => 'marketplace'])
            ->assertOk()
            ->assertJsonPath('data.preferred_view', 'marketplace');

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->getJson('/api/v1/business/workspace-access')
            ->assertOk()
            ->assertJsonPath('data.preferred_view', 'marketplace')
            ->assertJsonPath('data.available_views.2', 'combined');
    }

    public function test_granted_staff_can_manage_only_their_personal_view_from_settings(): void
    {
        [$tenant, $admin, $staff] = $this->fixture('workspace-staff-preference');
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->patchJson('/api/v1/business/workspace-access/users/'.$staff->id, ['access_mode' => 'marketplace_only'])
            ->assertOk();

        $this->actingAs($staff, 'sanctum')->withHeaders($headers)
            ->getJson('/api/v1/business/workspace-access')
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'marketplace_only')
            ->assertJsonPath('data.available_views.0', 'marketplace');

        $this->actingAs($staff, 'sanctum')->withHeaders($headers)
            ->patchJson('/api/v1/business/workspace-access/preference', ['preferred_view' => 'business'])
            ->assertOk()
            ->assertJsonPath('data.preferred_view', 'marketplace');
    }

    public function test_workspace_middleware_returns_forbidden_for_a_business_only_user_on_business_routes(): void
    {
        [$tenant, $admin, $staff] = $this->fixture('workspace-middleware');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->patchJson('/api/v1/business/workspace-access/users/'.$staff->id, ['access_mode' => 'marketplace_only'])->assertOk();

        $this->actingAs($staff, 'sanctum')->withHeaders($headers)->getJson('/api/v1/dashboard/overview')->assertForbidden();
    }

    public function test_workspace_controls_are_tenant_isolated_and_admin_access_cannot_be_removed(): void
    {
        [$tenant, $admin, $staff] = $this->fixture('workspace-isolation');
        [$otherTenant] = $this->fixture('workspace-other');
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $otherTenant->uuid)
            ->getJson('/api/v1/business/workspace-access')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->patchJson('/api/v1/business/workspace-access/users/'.$admin->id, ['access_mode' => 'business_only'])->assertForbidden();
        $this->assertDatabaseMissing('workspace_access_controls', ['tenant_id' => $tenant->id, 'user_id' => $staff->id]);
    }

    private function fixture(string $suffix): array
    {
        $tenant = Tenant::create(['name' => 'Workspace '.$suffix, 'slug' => 'workspace-'.strtolower($suffix), 'business_code' => 'WORKSPACE-'.strtoupper($suffix), 'status' => 'active', 'subscription_status' => 'trial']);
        $admin = User::factory()->create(['password' => Hash::make('CorrectPassword1!')]);
        $staff = User::factory()->create(['password' => Hash::make('CorrectPassword1!')]);
        $adminRole = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        $staffRole = Role::create(['tenant_id' => $tenant->id, 'name' => 'store_manager']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role_id' => $adminRole->id, 'status' => 'active', 'joined_at' => now()]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $staff->id, 'role_id' => $staffRole->id, 'status' => 'active', 'joined_at' => now()]);

        return [$tenant, $admin, $staff];
    }
}
