<?php

namespace Tests\Feature;

use App\Models\BusinessInvitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Jobs\SendBusinessInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use App\Services\BusinessInvitationService;
use Tests\TestCase;

class PhaseThreeBusinessManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_super_admin_session_can_restore_me_and_provision_a_business(): void
    {
        $superAdmin = User::factory()->create([
            'email' => 'platform-session@example.com',
            'password' => Hash::make('CorrectPassword1!'),
            'platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $superAdmin->email,
            'password' => 'CorrectPassword1!',
            'client_type' => 'web',
        ])->assertOk()->assertJsonPath('data.platform_role', User::PLATFORM_ROLE_SUPER_ADMIN);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $superAdmin->id)
            ->assertJsonPath('data.platform_role', User::PLATFORM_ROLE_SUPER_ADMIN);

        $created = $this->postJson('/api/v1/platform/businesses', [
            'business_name' => 'Business Session A',
            'business_code' => 'BUS-SESSION-A',
            'admin_name' => 'Session Admin',
            'admin_email' => 'session-admin@example.com',
        ])->assertCreated();

        $tenantUuid = $created->json('data.business.id');
        $tenant = Tenant::query()->where('uuid', $tenantUuid)->firstOrFail();

        $this->getJson('/api/v1/platform/businesses')
            ->assertOk()
            ->assertJsonFragment(['business_code' => 'BUS-SESSION-A']);
        $this->assertDatabaseHas('business_settings', ['tenant_id' => $tenant->id, 'currency_code' => 'MMK']);
        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $tenant->id, 'status' => 'pending']);
        $this->assertDatabaseHas('business_invitations', ['tenant_id' => $tenant->id, 'email' => 'session-admin@example.com']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.business_created', 'tenant_id' => $tenant->id]);
    }

    public function test_unauthenticated_user_cannot_provision_a_business(): void
    {
        $this->postJson('/api/v1/platform/businesses', [
            'business_name' => 'Unauthenticated Business',
            'business_code' => 'BUS-UNAUTH',
            'admin_name' => 'Admin',
            'admin_email' => 'unauth-admin@example.com',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_business_admin_cannot_provision_a_business(): void
    {
        [, , $businessAdmin] = $this->businessWithAdmin('BUS-OWNER', 'owner@example.com');

        $this->actingAs($businessAdmin, 'sanctum')
            ->postJson('/api/v1/platform/businesses', [
                'business_name' => 'Unauthorized Business',
                'business_code' => 'BUS-UNAUTHORIZED',
                'admin_name' => 'Admin',
                'admin_email' => 'unauthorized-admin@example.com',
            ])->assertForbidden();

        $this->assertDatabaseMissing('tenants', ['business_code' => 'BUS-UNAUTHORIZED']);
    }

    public function test_super_admin_can_provision_business_and_idempotency_prevents_duplicates(): void
    {
        Queue::fake();
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
            ->assertJsonPath('data.administrator.invitation_status', 'pending')
            ->assertJsonMissingPath('data.administrator.development_invitation_token');

        $this->actingAs($superAdmin, 'sanctum')
            ->withHeader('Idempotency-Key', 'business-a-creation')
            ->postJson('/api/v1/platform/businesses', $payload)
            ->assertCreated();

        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('business_settings', 1);
        $this->assertDatabaseCount('business_invitations', 1);
        Queue::assertPushed(SendBusinessInvitation::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.business_created']);
        $this->assertSame($first->json('data.business.id'), Tenant::first()->uuid);
    }

    public function test_existing_user_email_is_not_silently_assigned_to_a_new_business(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        User::factory()->create(['email' => 'existing@example.com']);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/v1/platform/businesses', [
                'business_name' => 'Business A',
                'business_code' => 'BUS-A',
                'admin_name' => 'Existing User',
                'admin_email' => 'existing@example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('admin_email');

        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('tenant_memberships', 0);
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

    public function test_business_admin_login_returns_active_memberships_for_dashboard_selection(): void
    {
        [$tenantA, , $admin] = $this->businessWithAdmin('BUS-A', 'multi-business-admin@example.com');
        [$tenantB] = $this->businessWithAdmin('BUS-B', 'other-admin@example.com');
        $roleB = Role::firstOrCreate(['tenant_id' => $tenantB->id, 'name' => 'business_admin']);
        TenantMembership::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $admin->id,
            'role_id' => $roleB->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'CorrectPassword1!',
            'client_type' => 'web',
        ])->assertOk()
            ->assertJsonCount(2, 'data.memberships')
            ->assertJsonPath('data.memberships.0.role', 'business_admin')
            ->assertJsonPath('data.memberships.0.status', 'active');

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonCount(2, 'data.memberships');

        $this->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->getJson('/api/v1/business/dashboard')
            ->assertOk()
            ->assertJsonPath('data.business.id', $tenantA->uuid);
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
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/invitations/accept', [
            'email' => $user->email,
            'token' => $token,
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword1!',
            'client_type' => 'web',
        ])->assertOk()
            ->assertJsonPath('data.memberships.0.role', 'business_admin')
            ->assertJsonPath('data.memberships.0.business.id', $tenant->uuid);

        $user->refresh();
        $this->assertTrue(Hash::check('CorrectPassword1!', $user->password));
        $this->assertNotSame('StrongInvitePassword1!', $user->password);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/invitations/accept', [
            'email' => $user->email,
            'token' => $token,
        ])->assertUnprocessable();
    }

    public function test_invitation_preview_hides_secrets_and_acceptance_is_single_use(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $tenant = Tenant::create(['name' => 'Preview Business', 'slug' => 'preview-business', 'business_code' => 'BUS-PREVIEW', 'status' => 'active']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        $user = User::factory()->create(['email' => 'preview-admin@example.com', 'status' => User::STATUS_INVITED]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'pending', 'invited_by' => $superAdmin->id]);
        $token = 'preview-token-with-at-least-32-characters-123';
        $invitation = BusinessInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'role' => 'business_admin',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
            'invited_by' => $superAdmin->id,
        ]);

        $this->getJson('/api/v1/auth/invitations/'.rawurlencode($token))
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.business.name', $tenant->name)
            ->assertJsonPath('data.password_setup_required', true)
            ->assertJsonMissingPath('data.token_hash')
            ->assertJsonMissingPath('data.token');

        $this->postJson('/api/v1/auth/invitations/accept', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'StrongInvitePassword1!',
            'password_confirmation' => 'StrongInvitePassword1!',
        ])->assertOk();

        $user->refresh();
        $invitation->refresh();
        $this->assertTrue(Hash::check('StrongInvitePassword1!', $user->password));
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertNotNull($invitation->accepted_at);
        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active']);

        $this->postJson('/api/v1/auth/invitations/accept', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'AnotherStrongPassword2@',
            'password_confirmation' => 'AnotherStrongPassword2@',
        ])->assertUnprocessable();
    }

    public function test_existing_active_user_must_be_authenticated_to_accept_membership_invitation(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $tenant = Tenant::create(['name' => 'Existing Account Business', 'slug' => 'existing-account-business', 'business_code' => 'BUS-EXISTING', 'status' => 'active']);
        $user = User::factory()->create(['email' => 'existing-admin@example.com', 'password' => Hash::make('OriginalPassword1!'), 'status' => User::STATUS_ACTIVE]);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/businesses/{$tenant->uuid}/admins", ['name' => 'Existing Admin', 'email' => $user->email])
            ->assertCreated()
            ->assertJsonPath('data.invitation_status', 'sent');

        [$invitation, $token] = app(BusinessInvitationService::class)->createPending($tenant, $user->email, 'business_admin', $superAdmin);

        $this->postJson('/api/v1/auth/invitations/accept', ['email' => $user->email, 'token' => $token])
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/invitations/accept', ['email' => $user->email, 'token' => $token])
            ->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('OriginalPassword1!', $user->password));
        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active']);
        $this->assertNotNull($invitation->refresh()->accepted_at);
    }

    public function test_super_admin_can_revoke_pending_invitation_and_business_admin_cannot(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $tenant = Tenant::create(['name' => 'Revoke Business', 'slug' => 'revoke-business', 'business_code' => 'BUS-REVOKE', 'status' => 'active']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        $user = User::factory()->create(['email' => 'revoke-admin@example.com', 'status' => User::STATUS_INVITED]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'pending']);
        $token = 'revoke-token-with-at-least-32-characters-123';
        $invitation = BusinessInvitation::create(['tenant_id' => $tenant->id, 'email' => $user->email, 'role' => 'business_admin', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/platform/businesses/{$tenant->uuid}/admins/{$user->id}/revoke-invitation")
            ->assertForbidden();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/businesses/{$tenant->uuid}/admins/{$user->id}/revoke-invitation")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertNotNull($invitation->refresh()->revoked_at);
        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'revoked']);
        $this->postJson('/api/v1/auth/invitations/accept', ['email' => $user->email, 'token' => $token, 'password' => 'StrongInvitePassword1!', 'password_confirmation' => 'StrongInvitePassword1!'])->assertUnprocessable();
    }

    public function test_invitation_password_rules_are_enforced(): void
    {
        [$tenant, $membership] = $this->businessWithAdmin('BUS-PASSWORD', 'password-admin@example.com');
        $user = User::query()->where('email', 'password-admin@example.com')->firstOrFail();
        $user->update(['status' => User::STATUS_INVITED]);
        $membership->update(['status' => 'pending', 'joined_at' => null]);
        $token = 'password-rules-invitation-token-123456789';
        BusinessInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'role' => 'business_admin',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        $this->postJson('/api/v1/auth/invitations/accept', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_revoked_membership_cannot_access_business_resources(): void
    {
        [$tenant, $membership, $admin] = $this->businessWithAdmin('BUS-A', 'admin-a@example.com');
        $membership->update(['status' => 'revoked']);

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/business/members')->assertForbidden();
    }

    public function test_super_admin_business_directory_has_statistics_owner_filters_and_pagination(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $tenantA = Tenant::create(['name' => 'Alpha Retail', 'slug' => 'alpha-retail', 'business_code' => 'ALPHA-001', 'email' => 'alpha@example.com', 'status' => 'active', 'subscription_status' => 'trial']);
        $tenantB = Tenant::create(['name' => 'Beta Retail', 'slug' => 'beta-retail', 'business_code' => 'BETA-001', 'email' => 'beta@example.com', 'status' => 'suspended', 'subscription_status' => 'active']);
        $owner = User::factory()->create(['name' => 'Alpha Owner', 'email' => 'alpha-owner@example.com', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $tenantA->id, 'name' => 'business_admin']);
        TenantMembership::create(['tenant_id' => $tenantA->id, 'user_id' => $owner->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/platform/businesses/statistics')
            ->assertOk()
            ->assertJsonPath('data.total_businesses', 2)
            ->assertJsonPath('data.active_businesses', 1)
            ->assertJsonPath('data.trial_businesses', 1)
            ->assertJsonPath('data.suspended_businesses', 1);

        $directory = $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/platform/businesses?search=alpha-owner&activation=verified&per_page=1');
        $directory
            ->assertOk()
            ->assertJsonFragment(['business_code' => 'ALPHA-001'])
            ->assertJsonPath('meta.total', 1);

        $export = $this->actingAs($superAdmin, 'sanctum')->get('/api/v1/platform/businesses/export?search=alpha-owner');
        $export->assertOk();
        $this->assertStringContainsString('Alpha Retail', $export->streamedContent());
        $this->assertStringContainsString('alpha-owner@example.com', $export->streamedContent());

        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/platform/businesses/'.$tenantA->uuid, ['subscription_status' => 'active'])
            ->assertOk();
        $this->assertDatabaseHas('tenants', ['id' => $tenantA->id, 'subscription_status' => 'trial']);
        $this->assertDatabaseHas('tenants', ['id' => $tenantB->id, 'subscription_status' => 'active']);
    }

    public function test_business_admin_cannot_access_business_directory_statistics_or_export(): void
    {
        [, , $businessAdmin] = $this->businessWithAdmin('BUS-DIRECTORY', 'directory-admin@example.com');

        $this->actingAs($businessAdmin, 'sanctum')->getJson('/api/v1/platform/businesses/statistics')->assertForbidden();
        $this->actingAs($businessAdmin, 'sanctum')->getJson('/api/v1/platform/businesses/export')->assertForbidden();
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
