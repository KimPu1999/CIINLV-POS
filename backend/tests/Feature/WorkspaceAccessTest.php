<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\BusinessInvitation;
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
        $this->assertSame([], $initial->json('data.members'));
        $member = $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->getJson('/api/v1/business/workspace-access?email='.rawurlencode($staff->email))
            ->assertOk()
            ->json('data.members.0');
        $this->assertSame('both', $member['access_mode']);

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

    public function test_business_admin_searches_members_by_email_without_initial_enumeration(): void
    {
        [$tenant, $admin, $staff] = $this->fixture('workspace-email-search');
        $staff->forceFill(['email' => 'staff@workspace.test'])->save();
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->getJson('/api/v1/business/workspace-access')
            ->assertOk()
            ->assertJsonPath('data.members', []);

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->getJson('/api/v1/business/workspace-access?email=staff%40workspace.test')
            ->assertOk()
            ->assertJsonPath('data.members.0.user_id', $staff->id)
            ->assertJsonPath('data.members.0.email', 'staff@workspace.test')
            ->assertJsonPath('data.members.0.membership_status', 'active')
            ->assertJsonPath('data.members.0.role', 'store_manager');

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->getJson('/api/v1/business/workspace-access?email=not-a-member%40workspace.test')
            ->assertOk()
            ->assertJsonPath('data.members', []);
    }

    public function test_pending_invitation_is_searchable_but_has_no_assignable_user_id(): void
    {
        [$tenant, $admin] = $this->fixture('workspace-invitation-search');
        $email = 'invited@workspace.test';
        $invited = User::factory()->create(['email' => $email, 'status' => User::STATUS_INVITED]);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'sales_staff']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $invited->id, 'role_id' => $role->id, 'status' => 'pending']);
        BusinessInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => $email,
            'role' => 'sales_staff',
            'token_hash' => hash('sha256', 'workspace-invitation-'.$tenant->id),
            'expires_at' => now()->addDay(),
            'invited_by' => $admin->id,
        ]);

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/business/workspace-access?email=invited%40workspace.test')
            ->assertOk()
            ->assertJsonPath('data.members.0.user_id', $invited->id)
            ->assertJsonPath('data.members.0.membership_status', 'invited')
            ->assertJsonPath('data.members.0.invitation_status', 'pending')
            ->assertJsonPath('data.members.0.access_mode', null);
    }

    public function test_admin_personal_view_can_be_restricted_without_restricting_admin_recovery_access(): void
    {
        [$tenant, $admin] = $this->fixture('workspace-admin-view');
        WorkspaceAccessControl::create([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'access_mode' => WorkspaceAccessControl::MODE_BUSINESS_ONLY,
            'preferred_view' => WorkspaceAccessControl::VIEW_BUSINESS,
        ]);

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->patchJson('/api/v1/business/workspace-access/preference', ['preferred_view' => 'marketplace'])
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'both')
            ->assertJsonPath('data.preferred_view', 'marketplace');

        $this->assertDatabaseHas('workspace_access_controls', [
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'access_mode' => WorkspaceAccessControl::MODE_BOTH,
            'preferred_view' => WorkspaceAccessControl::VIEW_MARKETPLACE,
        ]);
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
