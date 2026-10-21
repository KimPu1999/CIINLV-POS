<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_bounded_monitoring_signals(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'monitoring-failure-1',
            'connection' => 'sync',
            'queue' => 'default',
            'payload' => '{"command":"redacted-in-response"}',
            'exception' => "RuntimeException: should never be returned",
            'failed_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/monitoring')
            ->assertOk()
            ->assertJsonPath('data.health.api.status', 'ok')
            ->assertJsonPath('data.queue.failed_jobs_last_24h', 1)
            ->assertJsonPath('data.queue.recent_failures.0.queue', 'default')
            ->assertJsonMissingPath('data.queue.recent_failures.0.payload')
            ->assertJsonMissingPath('data.queue.recent_failures.0.exception')
            ->assertJsonPath('data.inventory.report_only', true)
            ->assertJsonPath('data.payments.provider_configured', false)
            ->assertJsonPath('data.backups.path_exposed', false);
    }

    public function test_non_platform_users_cannot_view_monitoring_signals(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/platform/monitoring')
            ->assertForbidden();
    }

    public function test_public_health_endpoint_does_not_expose_diagnostics(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertJsonStructure(['success', 'application', 'status', 'version'])
            ->assertJsonMissingPath('checks')
            ->assertJsonMissingPath('database');
    }
}
