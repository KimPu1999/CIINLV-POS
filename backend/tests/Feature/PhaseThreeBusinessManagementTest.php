<?php

namespace Tests\Feature;

use App\Models\BusinessInvitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseThreeBusinessManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_provision_business_and_idempotency_prevents_duplicates(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $payload = [
            'business_name' => 'Business A',
            'business_code' => 'BUS-A',
            'admin_name' => 'Admin A',
            'admin_email' => 'admin-a@example.com',
            'currency_code' => 'MMK',
            'timezone' => 'Asia/Yangon',
        ];

        $first = $this->actingAs($superAdmin, 'sanctum')
            ->withHeader('Idempotency-Key', 'business-a-creation')
            ->postJson('/api/v1/platform/businesses', $payload)
            ->assertCreated()
            ->assertJsonPath('data.business.business_code', 'BUS-A')
            ->assertJsonPath('data.administrator.invitation_status', 'pending');

        $this->actingAs($superAdmin, 'sanctum')
            ->withHeader('Idempotency-Key', 'business-a-creation')
            ->postJson('/api/v1/platform/businesses', $payload)
            ->assertCreated();

        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('business_settings', 1);
        $this->assertDatabaseCount('business_invitations', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.business_created']);
        $this->assertSame($first->json('data.business.id'), Tenant::first()->uuid);
    }

    public function test_duplicate_code_and_invalid_admin_email_are_rejected(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $payload = [
            'business_name' => 'Business A',
            'business_code' => 'BUS-A',
            'admin_name' => 'Admin A',
            'admin_email' => 'not-an-email',
        ];

        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/platform/businesses', $payload)->assertUnprocessable();

        $payload['admin_email'] = 'admin-a@example.com';
        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/platform/businesses', $payload)->assertCreated();
        $payload['business_name'] = 'Business B';
        $payload['admin_email'] = 'admin-b@example.com';
        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/platform/businesses', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_business_admin_is_isolated_and_can_update_only_own_business(): void
    {
        [$tenantA, $tenantB, $adminA] = $this->businessWithAdmin('BUS-A', 'admin-a@example.com');
        [$tenantB, , $adminB] = $this->businessWithAdmin('BUS-B', 'admin-b@example.com');

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->getJson('/api/v1/business/profile')->assertOk()->assertJsonPath('data.business_code', 'BUS-A');
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)
            ->getJson('/api/v1/business/profile')->assertForbidden();
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)
            ->patchJson('/api/v1/business/profile', ['name' => 'Hacked Business'])->assertForbidden();
        $this->actingAs($adminB, 'sanctum')->getJson('/api/v1/platform/dashboard')->assertForbidden();
    }

    public function test_business_admin_can_manage_profile_settings_and_dashboard(): void
    {
        [$tenant, , $admin] = $this->businessWithAdmin('BUS-A', 'admin-a@example.com');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->patchJson('/api/v1/business/profile', ['name' => 'Updated Business'])
            ->assertOk()->assertJsonPath('data.name', 'Updated Business');
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->patchJson('/api/v1/business/settings', ['currency_code' => 'USD', 'locale' => 'en-US'])
            ->assertOk()->assertJsonPath('data.currency_code', 'USD');
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/business/dashboard')->assertOk()->assertJsonPath('data.active_members', 1);
    }

    public function test_suspension_blocks_existing_business_sessions_and_activation_restores_access(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        [$tenant, , $admin] = $this->businessWithAdmin('BUS-A', 'admin-a@example.com');

        $this->actingAs($superAdmin, 'sanctum')->postJson("/api/v1/platform/businesses/{$tenant->uuid}/suspend")->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/business/profile')->assertForbidden();
        $this->actingAs($superAdmin, 'sanctum')->postJson("/api/v1/platform/businesses/{$tenant->uuid}/activate")->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/business/profile')->assertOk();
    }

    public function test_expired_and_replayed_invitations_are_rejected(): void
    {
        [$tenant] = $this->businessWithAdmin('BUS-A', 'admin-a@example.com');
        $user = User::query()->where('email', 'admin-a@example.com')->firstOrFail();
        $token = 'a-valid-test-invitation-token-123456789';
        $invitation = BusinessInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'role' => 'business_admin',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/auth/invitations/accept', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'StrongInvitePassword1!',
            'password_confirmation' => 'StrongInvitePassword1!',
        ])->assertUnprocessable();

        $invitation->update(['expires_at' => now()->addDay()]);
        $this->postJson('/api/v1/auth/invitations/accept', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'StrongInvitePassword1!',
            'password_confirmation' => 'StrongInvitePassword1!',
        ])->assertOk();
        $this->postJson('/api/v1/auth/invitations/accept', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'AnotherStrongPassword2@',
            'password_confirmation' => 'AnotherStrongPassword2@',
        ])->assertUnprocessable();
    }

    public function test_revoked_membership_cannot_access_business_resources(): void
    {
        [$tenant, $membership, $admin] = $this->businessWithAdmin('BUS-A', 'admin-a@example.com');
        $membership->update(['status' => 'revoked']);

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/business/members')->assertForbidden();
    }

    private function businessWithAdmin(string $code, string $email): array
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $tenant = Tenant::create([
            'name' => $code,
            'slug' => strtolower($code),
            'business_code' => $code,
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        $admin = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        $membership = TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'role_id' => $role->id,
            'status' => 'active',
            'invited_by' => $superAdmin->id,
            'joined_at' => now(),
        ]);

        return [$tenant, $membership, $admin];
    }
}
