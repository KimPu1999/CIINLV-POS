<?php

namespace Tests\Feature;

use App\Models\PlatformHealthHeartbeat;
use App\Models\PlatformJobTelemetry;
use App\Models\User;
use App\Services\PlatformHealthHeartbeatService;
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
            'exception' => 'RuntimeException: should never be returned',
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

    public function test_monitoring_api_exposes_persisted_healthy_and_stale_states(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        PlatformHealthHeartbeat::create([
            'key' => PlatformHealthHeartbeatService::QUEUE_WORKER,
            'status' => 'healthy',
            'last_success_at' => now()->subSeconds(10),
        ]);
        PlatformHealthHeartbeat::create([
            'key' => PlatformHealthHeartbeatService::SCHEDULER,
            'status' => 'healthy',
            'last_success_at' => now()->subSeconds((int) config('health.heartbeat_stale_seconds', 300) + 1),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/monitoring')
            ->assertOk()
            ->assertJsonPath('data.queue.status', 'healthy')
            ->assertJsonPath('data.health.scheduler.status', 'stale');

        $this->assertIsString($response->json('data.health.scheduler.last_success_at'));
    }

    public function test_monitoring_reports_completed_telemetry_without_job_payloads(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        PlatformJobTelemetry::create([
            'job_reference' => hash('sha256', 'monitoring-job'),
            'job_type' => 'SendStockAlert',
            'connection' => 'database',
            'queue' => 'default',
            'status' => 'completed',
            'attempts' => 1,
            'queued_at' => now()->subMinutes(3),
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
            'duration_ms' => 60000,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/monitoring?queue=default')
            ->assertOk()
            ->assertJsonPath('data.queue.completed_jobs_last_24h', 1)
            ->assertJsonPath('data.queue.average_processing_duration_ms', 60000)
            ->assertJsonPath('data.queue.history.0.job_type', 'SendStockAlert')
            ->assertJsonMissingPath('data.queue.history.0.payload');
    }

    public function test_sync_queue_reports_database_queue_metrics_as_unavailable(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/monitoring')
            ->assertOk()
            ->assertJsonPath('data.queue.driver', 'sync')
            ->assertJsonPath('data.queue.pending_jobs', null)
            ->assertJsonPath('data.queue.processing_jobs', null);
    }

    public function test_incomplete_telemetry_does_not_fabricate_duration(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        PlatformJobTelemetry::create([
            'job_reference' => hash('sha256', 'incomplete-monitoring-job'),
            'job_type' => 'RefreshReportingCache',
            'connection' => 'database',
            'queue' => 'default',
            'status' => 'processing',
            'attempts' => 1,
            'started_at' => null,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/monitoring')
            ->assertOk()
            ->assertJsonPath('data.queue.average_processing_duration_ms', null)
            ->assertJsonPath('data.queue.history.0.status', 'processing');
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
