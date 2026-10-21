<?php

namespace App\Services;

use App\Models\PlatformHealthHeartbeat;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PlatformHealthHeartbeatService
{
    public const QUEUE_WORKER = 'queue_worker';

    public const SCHEDULER = 'scheduler';

    public const STORAGE = 'storage';

    /**
     * Record evidence that a process or probe completed successfully.
     *
     * The table is intentionally optional until the reviewed migration is
     * executed. This keeps queue workers and web requests safe during rollout.
     */
    public function recordSuccess(string $key, array $metadata = []): bool
    {
        try {
            if (! $this->available()) {
                return false;
            }

            $now = now();
            $heartbeat = PlatformHealthHeartbeat::query()->where('key', $key)->first();
            $writeInterval = max(1, (int) config('health.heartbeat_write_interval_seconds', 30));

            if ($heartbeat?->last_success_at?->greaterThan($now->copy()->subSeconds($writeInterval))) {
                return true;
            }

            PlatformHealthHeartbeat::query()->updateOrCreate(
                ['key' => $key],
                [
                    'status' => 'healthy',
                    'last_success_at' => $now,
                    'last_error' => null,
                    'metadata' => $metadata ?: null,
                ],
            );

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Record a bounded operational error without exposing stack traces.
     */
    public function recordFailure(string $key, Throwable|string $error, array $metadata = []): bool
    {
        try {
            if (! $this->available()) {
                return false;
            }

            $message = $error instanceof Throwable ? $error->getMessage() : $error;
            $message = Str::limit(trim((string) preg_replace('/\s+/', ' ', $message)), 1000, '…');

            PlatformHealthHeartbeat::query()->updateOrCreate(
                ['key' => $key],
                [
                    'status' => 'unhealthy',
                    'last_failure_at' => now(),
                    'last_error' => $message !== '' ? $message : 'The health check failed.',
                    'metadata' => $metadata ?: null,
                ],
            );

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function available(): bool
    {
        try {
            return Schema::hasTable('platform_health_heartbeats');
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Resolve persisted evidence into a dashboard-safe state.
     *
     * A successful timestamp older than the threshold is stale, never healthy.
     */
    public function status(string $key): array
    {
        $staleAfter = max(1, (int) config('health.heartbeat_stale_seconds', 300));
        $base = [
            'status' => 'unknown',
            'reason' => null,
            'last_success_at' => null,
            'last_failure_at' => null,
            'last_error' => null,
            'stale_after_seconds' => $staleAfter,
        ];

        try {
            if (! $this->available()) {
                $base['reason'] = 'Health heartbeat storage is not available; the reviewed migration is pending.';

                return $base;
            }

            $heartbeat = PlatformHealthHeartbeat::query()->where('key', $key)->first();
            if (! $heartbeat) {
                $base['reason'] = 'No successful heartbeat has been recorded.';

                return $base;
            }

            $base['last_success_at'] = $heartbeat->last_success_at?->toIso8601String();
            $base['last_failure_at'] = $heartbeat->last_failure_at?->toIso8601String();
            $base['last_error'] = $heartbeat->last_error;

            if ($heartbeat->status === 'unhealthy') {
                $base['status'] = 'unhealthy';
                $base['reason'] = $heartbeat->last_error ?: 'The latest health check failed.';

                return $base;
            }

            if (! $heartbeat->last_success_at) {
                $base['reason'] = 'The heartbeat record has no successful timestamp.';

                return $base;
            }

            if ($heartbeat->last_success_at->lessThan(now()->subSeconds($staleAfter))) {
                $base['status'] = 'stale';
                $base['reason'] = "The last successful heartbeat is older than {$staleAfter} seconds.";

                return $base;
            }

            $base['status'] = 'healthy';
            $base['reason'] = 'A recent successful heartbeat was recorded.';

            return $base;
        } catch (Throwable $exception) {
            report($exception);
            $base['status'] = 'unhealthy';
            $base['reason'] = 'Health heartbeat storage could not be read.';

            return $base;
        }
    }
}
