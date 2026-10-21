<?php

namespace Tests\Unit;

use App\Models\PlatformHealthHeartbeat;
use App\Services\PlatformHealthHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformHealthHeartbeatServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_heartbeat_is_unknown(): void
    {
        $status = app(PlatformHealthHeartbeatService::class)->status('missing-service');

        $this->assertSame('unknown', $status['status']);
        $this->assertSame('No successful heartbeat has been recorded.', $status['reason']);
        $this->assertNull($status['last_success_at']);
    }

    public function test_recent_successful_heartbeat_is_healthy(): void
    {
        PlatformHealthHeartbeat::create([
            'key' => PlatformHealthHeartbeatService::QUEUE_WORKER,
            'status' => 'healthy',
            'last_success_at' => now()->subSeconds(10),
        ]);

        $status = app(PlatformHealthHeartbeatService::class)->status(PlatformHealthHeartbeatService::QUEUE_WORKER);

        $this->assertSame('healthy', $status['status']);
        $this->assertNotNull($status['last_success_at']);
    }

    public function test_failed_heartbeat_is_unhealthy(): void
    {
        PlatformHealthHeartbeat::create([
            'key' => PlatformHealthHeartbeatService::SCHEDULER,
            'status' => 'unhealthy',
            'last_failure_at' => now(),
            'last_error' => 'Scheduler command failed.',
        ]);

        $status = app(PlatformHealthHeartbeatService::class)->status(PlatformHealthHeartbeatService::SCHEDULER);

        $this->assertSame('unhealthy', $status['status']);
        $this->assertSame('Scheduler command failed.', $status['last_error']);
        $this->assertSame('Scheduler command failed.', $status['reason']);
    }

    public function test_old_successful_heartbeat_is_stale(): void
    {
        PlatformHealthHeartbeat::create([
            'key' => PlatformHealthHeartbeatService::STORAGE,
            'status' => 'healthy',
            'last_success_at' => now()->subSeconds((int) config('health.heartbeat_stale_seconds', 300) + 1),
        ]);

        $status = app(PlatformHealthHeartbeatService::class)->status(PlatformHealthHeartbeatService::STORAGE);

        $this->assertSame('stale', $status['status']);
        $this->assertStringContainsString('older than', $status['reason']);
    }
}
