<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminBusinessWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_must_select_a_business_before_opening_business_dashboard(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        Tenant::create(['name' => 'Selected later', 'slug' => 'selected-later', 'business_code' => 'SELECT-LATER', 'status' => 'active']);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/business/dashboard')
            ->assertBadRequest();
    }

    public function test_super_admin_can_open_only_the_explicitly_selected_active_business_without_membership(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $tenant = Tenant::create(['name' => 'Managed business', 'slug' => 'managed-business', 'business_code' => 'MANAGED-001', 'status' => 'active']);
        $otherTenant = Tenant::create(['name' => 'Other business', 'slug' => 'other-business', 'business_code' => 'OTHER-001', 'status' => 'active']);

        $this->actingAs($superAdmin, 'sanctum')
            ->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/business/dashboard')
            ->assertOk()
            ->assertJsonPath('data.business.id', $tenant->uuid);

        $this->assertDatabaseMissing('tenant_memberships', ['user_id' => $superAdmin->id, 'tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $superAdmin->id,
            'tenant_id' => $tenant->id,
            'action' => 'platform.business_context_accessed',
        ]);

        $this->actingAs($superAdmin, 'sanctum')
            ->withHeader('X-Tenant-Id', $otherTenant->uuid)
            ->getJson('/api/v1/business/dashboard')
            ->assertOk()
            ->assertJsonPath('data.business.id', $otherTenant->uuid);

        $this->assertSame(0, AuditLog::query()->where('actor_user_id', $superAdmin->id)->where('tenant_id', $tenant->id)->where('action', 'business.membership_created')->count());
    }

    public function test_super_admin_cannot_open_suspended_business_workspace(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $tenant = Tenant::create(['name' => 'Suspended business', 'slug' => 'suspended-business', 'business_code' => 'SUSPENDED-001', 'status' => 'suspended']);

        $this->actingAs($superAdmin, 'sanctum')
            ->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/business/dashboard')
            ->assertForbidden();
    }
}
