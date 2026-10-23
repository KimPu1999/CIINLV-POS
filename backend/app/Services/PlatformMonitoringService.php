<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PlatformMonitoringService
{
    public function __construct(
        private readonly DatabaseHealthCheck $databaseHealth,
        private readonly BackupMonitoringService $backups,
        private readonly SecurityAuditService $securityAudit,
        private readonly InventoryReconciliationService $inventory,
        private readonly PlatformHealthHeartbeatService $heartbeats,
        private readonly PlatformJobTelemetryService $jobTelemetry,
        private readonly PlatformSchedulerTelemetryService $schedulerTelemetry,
    ) {}

    /**
     * Return bounded operational signals for authorized platform staff.
     * This method never repairs inventory, retries jobs, changes payment state, or
     * exposes payloads, exception traces, credentials, or filesystem paths. The
     * storage probe only creates and removes a unique temporary health object.
     */
    public function overview(array $filters = []): array
    {
        $systemHealth = $this->systemHealth();
        $postgresql = $systemHealth['checks']['postgresql'] ?? ['status' => 'unavailable', 'checks' => []];

        $queueMetrics = $this->jobTelemetry->summary($filters);
        $schedulerHeartbeat = $systemHealth['checks']['scheduler'] ?? ['status' => 'unknown'];

        return [
            'checked_at' => now()->toIso8601String(),
            'health' => [
                'api' => ['status' => 'ok', 'evidence' => 'Authenticated platform monitoring request.'],
                'database' => [
                    'status' => match ($postgresql['status'] ?? null) {
                        'ready' => 'ok',
                        'failed' => 'failed',
                        default => 'unavailable',
                    },
                    'checks' => $postgresql['checks'] ?? [],
                ],
                'queue_worker' => $systemHealth['checks']['queue_worker'] ?? ['status' => 'unknown'],
                'scheduler' => $systemHealth['checks']['scheduler'] ?? ['status' => 'unknown'],
                'storage' => $systemHealth['checks']['storage'] ?? ['status' => 'unknown'],
            ],
            'queue' => array_merge($systemHealth['checks']['queue_worker'] ?? ['status' => 'unknown'], $queueMetrics),
            'scheduler_monitoring' => $this->schedulerTelemetry->summary($filters, $schedulerHeartbeat),
            'storage' => $systemHealth['checks']['storage'] ?? ['status' => 'unknown'],
            'backups' => $this->backupStatus(),
            'inventory' => $this->inventoryStatus(),
            'payments' => $this->paymentStatus(),
            'security' => $this->securityStatus(),
        ];
    }

    /**
     * Shared health payload for the platform overview and detailed monitoring
     * pages. Every non-database service is backed by persisted evidence.
     */
    public function systemHealth(): array
    {
        $database = ['status' => 'unavailable', 'checks' => []];

        try {
            $result = $this->databaseHealth->run();
            $database = [
                'status' => $result['healthy'] ? 'ready' : 'failed',
                'checks' => $result['checks'] ?? [],
            ];
        } catch (Throwable $exception) {
            report($exception);
        }

        $queue = $this->queue();

        return [
            'last_checked_at' => now()->toIso8601String(),
            'checks' => [
                'api' => [
                    'status' => 'authenticated',
                    'evidence' => 'Authenticated platform monitoring request.',
                ],
                'postgresql' => $database,
                'queue_worker' => $queue,
                'scheduler' => $this->scheduler(),
                'storage' => $this->storage(),
            ],
            'recent_failed_jobs' => $queue['failed_jobs_last_24h'] ?? null,
            'monitoring_note' => 'Queue, scheduler, and storage statuses require a recent persisted heartbeat. Stale or missing evidence is not reported as ready.',
        ];
    }

    private function queue(): array
    {
        $heartbeat = $this->heartbeats->status(PlatformHealthHeartbeatService::QUEUE_WORKER);
        $result = array_merge($heartbeat, [
            'failed_jobs_last_24h' => null,
            'recent_failures' => [],
        ]);

        try {
            if (! Schema::hasTable('failed_jobs')) {
                $result['failed_jobs_reason'] = 'Failed-job storage is not available.';

                return $result;
            }

            $since = now()->subDay();
            $failedJobs = DB::table('failed_jobs')->where('failed_at', '>=', $since)->count();
            $recent = DB::table('failed_jobs')
                ->where('failed_at', '>=', $since)
                ->latest('failed_at')
                ->limit(10)
                ->get(['id', 'uuid', 'queue', 'failed_at'])
                ->map(fn (object $job): array => [
                    'reference' => substr(hash('sha256', (string) ($job->uuid ?: $job->id)), 0, 16),
                    'queue' => (string) $job->queue,
                    'failed_at' => $job->failed_at,
                ])->values()->all();

            $result['failed_jobs_last_24h'] = $failedJobs;
            $result['recent_failures'] = $recent;

            return $result;
        } catch (Throwable $exception) {
            report($exception);
            $result['failed_jobs_reason'] = 'Failed-job monitoring is temporarily unavailable.';

            return $result;
        }
    }

    private function scheduler(): array
    {
        return $this->heartbeats->status(PlatformHealthHeartbeatService::SCHEDULER);
    }

    private function storage(): array
    {
        $probe = $this->probeStorage();
        $metadata = ['disks' => $probe['disks']];

        if ($probe['status'] === 'healthy') {
            $this->heartbeats->recordSuccess(PlatformHealthHeartbeatService::STORAGE, $metadata);
        } elseif ($probe['status'] === 'unhealthy') {
            $this->heartbeats->recordFailure(PlatformHealthHeartbeatService::STORAGE, $probe['reason'] ?? 'Storage probe failed.', $metadata);
        }

        $heartbeat = $this->heartbeats->status(PlatformHealthHeartbeatService::STORAGE);

        return array_merge($heartbeat, [
            'probe_status' => $probe['status'],
            'disks' => $probe['disks'],
            'private_storage_configured' => in_array('private', array_keys($probe['disks']), true)
                && ($probe['disks']['private']['configured'] ?? false),
            'last_probe_at' => $probe['checked_at'],
        ]);
    }

    /**
     * Exercise the configured Laravel filesystem disk(s) without touching
     * application data. Each probe uses a unique temporary object and removes
     * it in a finally block.
     */
    private function probeStorage(): array
    {
        $disks = array_values(array_unique(array_filter((array) config('health.storage_disks', []))));
        $results = [];
        $hasFailure = false;
        $hasConfiguredDisk = false;
        $firstError = null;

        foreach ($disks as $disk) {
            $configured = is_array(config("filesystems.disks.{$disk}"));
            $result = ['configured' => $configured, 'status' => 'unknown', 'error' => null];

            if (! $configured) {
                $results[$disk] = $result;

                continue;
            }

            $hasConfiguredDisk = true;
            $path = 'health-checks/'.Str::uuid().'.txt';
            $written = false;

            try {
                $filesystem = Storage::disk($disk);
                $contents = 'ciinlv-platform-health';

                if (! $filesystem->put($path, $contents)) {
                    throw new \RuntimeException('Filesystem write probe failed.');
                }
                $written = true;

                if ($filesystem->get($path) !== $contents) {
                    throw new \RuntimeException('Filesystem read probe failed.');
                }

                if (! $filesystem->delete($path) && $filesystem->exists($path)) {
                    throw new \RuntimeException('Filesystem cleanup probe failed.');
                }
                $written = false;
                $result['status'] = 'healthy';
            } catch (Throwable $exception) {
                report($exception);
                $hasFailure = true;
                $firstError ??= $exception->getMessage();
                $result['status'] = 'unhealthy';
                $result['error'] = Str::limit(trim((string) preg_replace('/\s+/', ' ', $exception->getMessage())), 500, '…');
            } finally {
                if ($written) {
                    try {
                        Storage::disk($disk)->delete($path);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            }

            $results[$disk] = $result;
        }

        return [
            'status' => ! $disks || ! $hasConfiguredDisk ? 'unknown' : ($hasFailure ? 'unhealthy' : 'healthy'),
            'reason' => ! $disks || ! $hasConfiguredDisk
                ? 'No configured Laravel filesystem disk is available for probing.'
                : ($hasFailure ? ($firstError ?: 'At least one configured filesystem disk failed.') : 'Configured filesystem disk probes passed.'),
            'disks' => $results,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function backupStatus(): array
    {
        try {
            $result = $this->backups->inspect();

            return [
                'status' => $result['healthy'] ? 'ok' : 'unavailable',
                'file_count' => $result['file_count'],
                'latest_at' => $result['latest_at'] ? date(DATE_ATOM, $result['latest_at']) : null,
                'configured' => $result['path'] !== '',
                'path_exposed' => false,
                'reason' => $result['healthy'] ? null : 'No backup artifact was found in the configured backup directory.',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unavailable', 'file_count' => 0, 'latest_at' => null, 'configured' => false, 'path_exposed' => false];
        }
    }

    private function inventoryStatus(): array
    {
        try {
            $result = $this->inventory->summary(sampleLimit: 25);
            $discrepancyCount = $result['discrepancy_count'];

            return [
                'status' => $discrepancyCount === 0 ? 'ok' : 'warning',
                'checked' => $result['checked'],
                'discrepancy_count' => $discrepancyCount,
                'sample' => $result['sample'],
                'report_only' => true,
                'reason' => 'Inventory monitoring is report-only. Corrections must use authorized stock services.',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unavailable', 'checked' => 0, 'discrepancy_count' => null, 'sample' => [], 'report_only' => true];
        }
    }

    private function paymentStatus(): array
    {
        try {
            if (! Schema::hasTable('marketplace_payments')) {
                return ['status' => 'not_configured', 'provider_configured' => false, 'by_status' => []];
            }

            $byStatus = DB::table('marketplace_payments')
                ->where('created_at', '>=', now()->subDay())
                ->select('status')
                ->selectRaw('COUNT(*) AS count')
                ->groupBy('status')
                ->orderBy('status')
                ->get()
                ->map(fn (object $row): array => ['status' => (string) $row->status, 'count' => (int) $row->count])
                ->values()->all();

            return [
                'status' => 'not_configured',
                'provider_configured' => false,
                'by_status' => $byStatus,
                'reason' => 'No verified online payment provider is configured. COD and stored payment states remain visible for reconciliation.',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unavailable', 'provider_configured' => false, 'by_status' => []];
        }
    }

    private function securityStatus(): array
    {
        try {
            $audit = $this->securityAudit->run();
            $application = $audit['application'] ?? [];
            $database = $audit['database'] ?? [];

            return [
                'status' => isset($database['status']) ? 'unavailable' : 'ok',
                'debug_enabled' => (bool) ($application['debug'] ?? false),
                'force_https' => (bool) ($application['force_https'] ?? false),
                'secure_session_cookie' => (bool) ($application['session_secure_cookie'] ?? false),
                'policy_count' => isset($database['policy_count']) ? (int) $database['policy_count'] : null,
                'details_available' => true,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unavailable', 'details_available' => false];
        }
    }
}
