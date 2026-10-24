<?php

namespace Tests\Feature;

use App\Models\PlatformHealthHeartbeat;
use App\Models\PlatformJobTelemetry;
use App\Models\User;
use App\Services\PlatformHealthHeartbeatService;
use App\Services\PlatformJobTelemetryService;
use App\Services\PlatformSchedulerTelemetryService;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
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

    public function test_queue_telemetry_duration_is_persisted_as_integer_milliseconds(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        PlatformJobTelemetry::create([
            'job_reference' => hash_hmac('sha256', 'sync|default|fractional-duration-job', (string) config('app.key')),
            'job_type' => 'SendStockAlert',
            'connection' => 'sync',
            'queue' => 'default',
            'status' => 'processing',
            'attempts' => 1,
            'started_at' => now()->subMilliseconds(557),
        ]);

        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('getJobId')->andReturn('fractional-duration-job');
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldReceive('getConnectionName')->andReturn('sync');
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('resolveName')->andReturn('SendStockAlert');

        app(PlatformJobTelemetryService::class)->recordProcessed($job);

        $this->assertIsInt(PlatformJobTelemetry::query()->where('job_reference', hash_hmac('sha256', 'sync|default|fractional-duration-job', (string) config('app.key')))->value('duration_ms'));
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/monitoring')->assertOk();
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

    public function test_monitoring_exposes_safe_performance_release_and_incident_shapes(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/monitoring')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'performance' => ['status', 'monitoring_request_ms', 'error_rate_24h', 'error_rate_reason'],
                    'deployment' => ['status', 'version', 'commit', 'deployed_at', 'provider', 'source', 'reason'],
                    'incidents',
                ],
            ]);

        $this->assertIsInt($response->json('data.performance.monitoring_request_ms'));
        $this->assertNull($response->json('data.performance.error_rate_24h'));
        $this->assertIsArray($response->json('data.incidents'));
        $this->assertFalse($response->json('data.backups.path_exposed'));
    }

    public function test_scheduler_monitoring_lists_registered_tasks_without_fabricating_execution_success(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/monitoring')
            ->assertOk()
            ->assertJsonPath('data.scheduler_monitoring.storage_available', true)
            ->assertJsonPath('data.scheduler_monitoring.registered_task_count', 4)
            ->assertJsonPath('data.scheduler_monitoring.successful_executions_last_24h', 0)
            ->assertJsonPath('data.scheduler_monitoring.tasks.0.current_status', 'unknown');
    }

    public function test_scheduler_lifecycle_events_record_duration_and_success(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $schedule = app(Schedule::class);
        $event = collect($schedule->events())->first(fn ($task): bool => str_contains((string) $task->command, 'ciinlv:backup-health'));
        $this->assertNotNull($event);

        $telemetry = app(PlatformSchedulerTelemetryService::class);
        $telemetry->recordStarting(new ScheduledTaskStarting($event));
        $telemetry->recordFinished(new ScheduledTaskFinished($event, 1.25));

        $this->assertDatabaseHas('platform_scheduler_telemetry', [
            'command_identifier' => 'ciinlv:backup-health',
            'status' => 'completed',
            'duration_ms' => 1250,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/monitoring?task=backup-health')
            ->assertOk()
            ->assertJsonPath('data.scheduler_monitoring.successful_executions_last_24h', 1)
            ->assertJsonPath('data.scheduler_monitoring.tasks.0.status', 'healthy')
            ->assertJsonPath('data.scheduler_monitoring.history.0.duration_ms', 1250);
        $this->assertIsString($response->json('data.scheduler_monitoring.tasks.0.next_due_at'));
    }

    public function test_scheduler_failed_and_skipped_runs_are_visible_without_raw_exceptions(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $service = app(PlatformSchedulerTelemetryService::class);
        $tasks = $service->registeredTasks();
        $failedTask = collect($tasks)->firstWhere('command_identifier', 'ciinlv:inventory-reconcile');
        $skippedTask = collect($tasks)->firstWhere('command_identifier', 'marketplace:release-expired-reservations');
        $failedEvent = collect(app(Schedule::class)->events())->first(fn ($task): bool => str_contains((string) $task->command, 'ciinlv:inventory-reconcile'));
        $skippedEvent = collect(app(Schedule::class)->events())->first(fn ($task): bool => str_contains((string) $task->command, 'marketplace:release-expired-reservations'));

        $this->assertNotNull($failedTask);
        $this->assertNotNull($skippedTask);
        $this->assertNotNull($failedEvent);
        $this->assertNotNull($skippedEvent);
        $service->recordFailed(new ScheduledTaskFailed($failedEvent, new \RuntimeException('Inventory command failed safely.'), 0.5));
        $service->recordSkipped(new ScheduledTaskSkipped($skippedEvent));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/monitoring')
            ->assertOk()
            ->assertJsonPath('data.scheduler_monitoring.failed_executions_last_24h', 1)
            ->assertJsonPath('data.scheduler_monitoring.last_errors.0.error_summary', 'Inventory command failed safely.')
            ->assertJsonMissingPath('data.scheduler_monitoring.last_errors.0.exception');
    }
}
