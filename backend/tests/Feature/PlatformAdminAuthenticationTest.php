<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PlatformAdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_role_is_verified_by_the_platform_middleware(): void
    {
        $admin = User::factory()->create([
            'platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->assertTrue($admin->isSuperAdmin());

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/dashboard')
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/dashboard')
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/settings')
            ->assertOk();
    }

    public function test_auth_me_serializes_the_authenticated_platform_identity(): void
    {
        $admin = User::factory()->create([
            'platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.email', $admin->email)
            ->assertJsonPath('data.platform_role', User::PLATFORM_ROLE_SUPER_ADMIN)
            ->assertJsonPath('data.status', User::STATUS_ACTIVE);
    }

    public function test_business_account_cannot_access_platform_administrator_routes(): void
    {
        $businessAdmin = User::factory()->create([
            'platform_role' => null,
            'status' => User::STATUS_ACTIVE,
        ]);
        $diagnosticContext = [];

        Log::listen(function ($event) use (&$diagnosticContext): void {
            if ($event->message !== 'Platform administrator authorization denied.') {
                return;
            }

            $diagnosticContext = $event->context;
        });

        foreach (['/api/v1/platform/dashboard', '/api/v1/admin/marketplace/dashboard', '/api/v1/admin/marketplace/settings'] as $platformRoute) {
            $this->actingAs($businessAdmin, 'sanctum')
                ->getJson($platformRoute)
                ->assertForbidden()
                ->assertJsonPath('message', 'Platform administrator access is required.');
        }

        $this->assertSame($businessAdmin->id, $diagnosticContext['authenticated_user_id']);
        $this->assertSame(null, $diagnosticContext['platform_role']);
        $this->assertSame('sanctum', $diagnosticContext['auth_guard']);
        $this->assertArrayNotHasKey('authorization', $diagnosticContext);
        $this->assertArrayNotHasKey('cookie', $diagnosticContext);
        $this->assertArrayNotHasKey('password', $diagnosticContext);
        $this->assertArrayNotHasKey('token', $diagnosticContext);
    }

    public function test_stateful_web_logout_clears_the_session_without_deleting_sanctum_transient_token(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'client_type' => 'web',
        ])->assertOk();

        $this->withHeader('Origin', 'http://127.0.0.1:5173')
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->withHeader('Origin', 'http://127.0.0.1:5173')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_super_admin_web_login_replaces_a_previous_business_session(): void
    {
        $businessAdmin = User::factory()->create([
            'email' => 'business-session@example.com',
            'platform_role' => null,
        ]);
        $superAdmin = User::factory()->create([
            'email' => 'platform-session@example.com',
            'platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $businessAdmin->email,
            'password' => 'password',
            'client_type' => 'web',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $superAdmin->email,
            'password' => 'password',
            'client_type' => 'web',
        ])->assertOk();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $superAdmin->id)
            ->assertJsonPath('data.platform_role', User::PLATFORM_ROLE_SUPER_ADMIN);

        $this->getJson('/api/v1/platform/dashboard')->assertOk();
    }
}
