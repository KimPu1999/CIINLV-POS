<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PlatformMonitoringService
{
    public function __construct(
        private readonly DatabaseHealthCheck $databaseHealth,
        private readonly BackupMonitoringService $backups,
        private readonly SecurityAuditService $securityAudit,
        private readonly InventoryReconciliationService $inventory,
    ) {}

    /**
     * Return bounded, read-only operational signals for authorized platform staff.
     * This method never repairs inventory, retries jobs, changes payment state, or
     * exposes payloads, exception traces, credentials, or filesystem paths.
     */
    public function overview(): array
    {
        return [
            'checked_at' => now()->toIso8601String(),
            'health' => $this->health(),
            'queue' => $this->queue(),
            'storage' => $this->storage(),
            'backups' => $this->backupStatus(),
            'inventory' => $this->inventoryStatus(),
            'payments' => $this->paymentStatus(),
            'security' => $this->securityStatus(),
        ];
    }

    private function health(): array
    {
        try {
            $result = $this->databaseHealth->run();

            return [
                'api' => ['status' => 'ok'],
                'database' => ['status' => $result['healthy'] ? 'ok' : 'failed', 'checks' => $result['checks']],
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'api' => ['status' => 'ok'],
                'database' => ['status' => 'unavailable'],
            ];
        }
    }

    private function queue(): array
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return ['status' => 'unknown', 'reason' => 'Failed-job storage is not available.'];
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

            return [
                // A failed_jobs table is evidence of failures, not a worker heartbeat.
                'status' => 'unknown',
                'failed_jobs_last_24h' => $failedJobs,
                'recent_failures' => $recent,
                'reason' => 'Queue worker heartbeat monitoring is not persisted by the current schema.',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unavailable', 'reason' => 'Queue monitoring is temporarily unavailable.'];
        }
    }

    private function storage(): array
    {
        $privateRoot = (string) config('filesystems.disks.private.root', storage_path('app/private'));

        return [
            'status' => File::isDirectory($privateRoot) ? 'ok' : 'unavailable',
            'private_storage_configured' => File::isDirectory($privateRoot),
            'reason' => 'Storage capacity and remote object-store health require provider-specific monitoring.',
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
