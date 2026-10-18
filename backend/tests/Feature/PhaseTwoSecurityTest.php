<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PhaseTwoSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_login_and_compatible_me_endpoint_work(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com', 'password' => Hash::make('CorrectPassword1!')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword1!',
        ])->assertOk()->assertJsonPath('data.email', $user->email);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_wrong_password_is_rejected_and_login_is_rate_limited(): void
    {
        $user = User::factory()->create(['email' => 'throttle@example.com', 'password' => Hash::make('CorrectPassword1!')]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_mobile_login_issues_device_token_and_logout_revokes_it(): void
    {
        $user = User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('CorrectPassword1!')]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword1!',
            'client_type' => 'mobile',
            'device_name' => 'test-device',
        ])->assertOk();

        $token = $login->json('token');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_non_super_admin_cannot_access_platform_endpoints(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/platform/dashboard')
            ->assertForbidden();
    }

    public function test_super_admin_can_create_business_and_admin_but_duplicate_email_is_rejected(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $tenantResponse = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/v1/platform/tenants', ['name' => 'Business A'])
            ->assertCreated();
        $tenantId = $tenantResponse->json('data.id');

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/tenants/{$tenantId}/admins", ['name' => 'Admin A', 'email' => 'admin-a@example.com'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['development_invitation_token']]);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/v1/platform/tenants/{$tenantId}/admins", ['name' => 'Admin A2', 'email' => 'admin-a@example.com'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('tenants', ['name' => 'Business A']);
        $this->assertDatabaseHas('tenant_memberships', ['status' => 'active']);
    }

    public function test_business_members_cannot_select_another_tenant(): void
    {
        [$tenantA, $tenantB] = [Tenant::create(['name' => 'A', 'slug' => 'a']), Tenant::create(['name' => 'B', 'slug' => 'b'])];
        $roleA = Role::create(['tenant_id' => $tenantA->id, 'name' => 'business_admin']);
        $user = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenantA->id, 'user_id' => $user->id, 'role_id' => $roleA->id, 'status' => 'active']);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-Id', $tenantB->uuid)
            ->getJson('/api/v1/business/profile')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->getJson('/api/v1/business/profile')
            ->assertOk()->assertJsonPath('data.id', $tenantA->uuid);
    }

    public function test_suspended_tenant_and_revoked_membership_are_blocked(): void
    {
        $tenant = Tenant::create(['name' => 'Suspended', 'slug' => 'suspended', 'status' => 'suspended']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        $user = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($user, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/business/dashboard')->assertForbidden();

        $tenant->update(['status' => 'active']);
        TenantMembership::where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->actingAs($user, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/business/dashboard')->assertForbidden();
    }

    public function test_suspended_account_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => Hash::make('CorrectPassword1!'),
            'status' => User::STATUS_SUSPENDED,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword1!',
        ])->assertForbidden();
    }

    public function test_password_change_revokes_mobile_tokens(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CorrectPassword1!')]);
        $token = $user->createToken('test-device', ['auth:mobile'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'CorrectPassword1!',
            'password' => 'NewCorrectPassword2@',
            'password_confirmation' => 'NewCorrectPassword2@',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_password_reset_token_is_single_use(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $token = Password::broker()->createToken($user);
        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ResetCorrectPassword3#',
            'password_confirmation' => 'ResetCorrectPassword3#',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable();
    }
}
