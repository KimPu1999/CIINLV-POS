<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSecurityManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_filtered_security_overview_and_audit_details(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $tenant = Tenant::create(['name' => 'Security Business', 'slug' => 'security-business', 'business_code' => 'SECURITY', 'status' => 'active']);
        AuditLog::create(['actor_user_id' => $admin->id, 'tenant_id' => $tenant->id, 'action' => 'auth.login_succeeded', 'subject_type' => User::class, 'subject_id' => (string) $admin->id, 'metadata' => ['ip_address' => '192.0.2.10', 'secret' => 'should-not-be-exposed'], 'created_at' => now()]);
        $failed = AuditLog::create(['actor_user_id' => $admin->id, 'tenant_id' => null, 'action' => 'auth.login_failed', 'metadata' => ['reason' => 'invalid_credentials'], 'created_at' => now()]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/security/overview?days=30')
            ->assertOk()->assertJsonPath('data.metrics.total_audit_events', 2)->assertJsonPath('data.metrics.successful_logins', 1)->assertJsonPath('data.metrics.failed_login_attempts', 1);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/security/audit-logs?action=auth.login_failed')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $failed->id);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/security/audit-logs/'.$failed->id)
            ->assertOk()->assertJsonPath('data.event.action', 'auth.login_failed')->assertJsonPath('data.event.metadata.reason', 'invalid_credentials');
    }

    public function test_business_user_cannot_access_platform_security_endpoints(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/platform/security/overview')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/platform/security/audit-logs')->assertForbidden();
    }

    public function test_login_history_and_policies_report_supported_data_without_fabrication(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        AuditLog::create(['actor_user_id' => $admin->id, 'action' => 'auth.logout', 'metadata' => [], 'created_at' => now()]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/security/login-history')->assertOk()->assertJsonPath('data.total', 1);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/security/policies')->assertOk()->assertJsonPath('data.read_only', true)->assertJsonPath('data.policies.5.status', 'unavailable');
    }

    public function test_security_export_is_limited_and_formula_safe(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        AuditLog::create(['actor_user_id' => $admin->id, 'action' => '=unsafe_action', 'subject_id' => '=formula', 'metadata' => [], 'created_at' => now()]);

        $response = $this->actingAs($admin, 'sanctum')->get('/api/v1/platform/security/audit-logs/export');
        $response->assertOk();
        $this->assertStringContainsString("'=unsafe_action", $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.security_audit_exported']);
    }

    public function test_session_revocation_requires_super_admin_reauthentication_and_hides_raw_session_id(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        \DB::table('sessions')->insert(['id' => 'secret-session-id', 'user_id' => $admin->id, 'ip_address' => '192.0.2.20', 'user_agent' => 'Test Browser', 'payload' => 'encrypted', 'last_activity' => now()->timestamp]);

        $sessions = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/security/sessions')->assertOk();
        $reference = $sessions->json('data.sessions.0.reference');
        $this->assertNotSame('secret-session-id', $reference);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/security/sessions/revoke', ['session_reference' => $reference, 'password' => 'password', 'confirm' => true])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'secret-session-id']);
    }
}
