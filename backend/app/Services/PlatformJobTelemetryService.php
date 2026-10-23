<?php

namespace App\Services;

use App\Models\PlatformJobTelemetry;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PlatformJobTelemetryService
{
    private const HISTORY_LIMIT = 100;

    public function recordProcessing(Job $job): void
    {
        try {
            if (! $this->available()) {
                return;
            }

            $now = now();
            PlatformJobTelemetry::query()->updateOrCreate(
                ['job_reference' => $this->reference($job)],
                [
                    'job_type' => $this->jobType($job),
                    'connection' => $this->connection($job),
                    'queue' => (string) ($job->getQueue() ?: config('queue.connections.'.config('queue.default').'.queue', 'default')),
                    'status' => 'processing',
                    'attempts' => $job->attempts(),
                    'queued_at' => $this->queuedAt($job, $now),
                    'started_at' => $now,
                    'finished_at' => null,
                    'duration_ms' => null,
                    'last_error' => null,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function recordProcessed(Job $job): void
    {
        $this->recordFinished($job, 'completed');
    }

    public function recordFailed(Job $job, Throwable|string $error): void
    {
        $this->recordFinished($job, 'failed', $error);
    }

    public function available(): bool
    {
        try {
            return Schema::hasTable('platform_job_telemetry');
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function summary(array $filters = []): array
    {
        $driver = (string) config('queue.connections.'.config('queue.default').'.driver', config('queue.default'));
        $connection = (string) config('queue.default');
        $queue = (string) ($filters['queue'] ?: config("queue.connections.{$connection}.queue", 'default'));
        $jobsTable = (string) config("queue.connections.{$connection}.table", 'jobs');
        $jobsAvailable = $driver === 'database' && $this->hasTable($jobsTable);
        $failedAvailable = $this->hasTable((string) config('queue.failed.table', 'failed_jobs'));
        $telemetryAvailable = $this->available();
        $since = $this->dateFilter($filters['from'] ?? null, now()->subDay());
        $until = $this->dateFilter($filters['to'] ?? null, now())->endOfDay();

        $pending = null;
        $processing = null;
        $oldestWaitingAge = null;
        if ($jobsAvailable) {
            $base = DB::table($jobsTable)->where('queue', $queue);
            $pendingQuery = (clone $base)->whereNull('reserved_at')->where('available_at', '<=', now()->timestamp);
            $processingQuery = (clone $base)->whereNotNull('reserved_at');
            $pending = $pendingQuery->count();
            $processing = $processingQuery->count();
            $oldest = $pendingQuery->min('created_at');
            $oldestWaitingAge = $oldest === null ? null : max(0, now()->timestamp - (int) $oldest);
        }

        $telemetry = $this->filteredTelemetry($filters, $since, $until, $queue);
        $completed = $telemetryAvailable ? (clone $telemetry)->where('status', 'completed')->count() : null;
        $average = $telemetryAvailable ? (clone $telemetry)->where('status', 'completed')->whereNotNull('duration_ms')->avg('duration_ms') : null;

        return [
            'connection' => $connection,
            'driver' => $driver,
            'queue' => $queue,
            'queue_storage_available' => $jobsAvailable,
            'failed_storage_available' => $failedAvailable,
            'telemetry_available' => $telemetryAvailable,
            'pending_jobs' => $pending,
            'processing_jobs' => $processing,
            'completed_jobs_last_24h' => $completed,
            'failed_jobs_last_24h' => $failedAvailable ? $this->failedCount($filters, $since, $until, $queue) : null,
            'oldest_waiting_job_age_seconds' => $oldestWaitingAge,
            'average_processing_duration_ms' => $average === null ? null : (int) round((float) $average),
            'history' => $this->history($filters, $since, $until, $queue, $jobsAvailable, $failedAvailable, $telemetryAvailable),
            'activity' => $this->activity($filters, $since, $until, $queue, $telemetryAvailable),
            'reason' => $this->reason($driver, $jobsAvailable, $failedAvailable, $telemetryAvailable),
        ];
    }

    private function recordFinished(Job $job, string $status, Throwable|string|null $error = null): void
    {
        try {
            if (! $this->available()) {
                return;
            }

            $now = now();
            $reference = $this->reference($job);
            $row = PlatformJobTelemetry::query()->where('job_reference', $reference)->first();
            $startedAt = $row?->started_at;
            $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;

            PlatformJobTelemetry::query()->updateOrCreate(
                ['job_reference' => $reference],
                [
                    'job_type' => $row?->job_type ?: $this->jobType($job),
                    'connection' => $row?->connection ?: $this->connection($job),
                    'queue' => $row?->queue ?: (string) $job->getQueue(),
                    'status' => $status,
                    'attempts' => $job->attempts(),
                    'queued_at' => $row?->queued_at ?: $this->queuedAt($job, $now),
                    'started_at' => $startedAt ?: $now,
                    'finished_at' => $now,
                    'duration_ms' => $startedAt ? max(0, $startedAt->diffInMilliseconds($now)) : null,
                    'last_error' => $message !== '' ? Str::limit(trim((string) preg_replace('/\s+/', ' ', $message)), 1000, '…') : null,
                ],
            );

            PlatformJobTelemetry::query()
                ->whereNotNull('finished_at')
                ->where('finished_at', '<', now()->subDays((int) config('health.job_telemetry_retention_days', 7)))
                ->delete();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function filteredTelemetry(array $filters, Carbon $since, Carbon $until, string $queue)
    {
        $query = PlatformJobTelemetry::query()
            ->whereBetween('finished_at', [$since, $until])
            ->when($queue !== '', fn ($builder) => $builder->where('queue', $queue))
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status));

        return $query;
    }

    private function failedCount(array $filters, Carbon $since, Carbon $until, string $queue): int
    {
        return (int) DB::table((string) config('queue.failed.table', 'failed_jobs'))
            ->whereBetween('failed_at', [$since, $until])
            ->when($queue !== '', fn ($query) => $query->where('queue', $queue))
            ->count();
    }

    private function history(array $filters, Carbon $since, Carbon $until, string $queue, bool $jobsAvailable, bool $failedAvailable, bool $telemetryAvailable): array
    {
        $rows = collect();
        if ($telemetryAvailable) {
            $telemetryQuery = PlatformJobTelemetry::query()
                ->where('queue', $queue)
                ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
                ->where(function ($builder) use ($since, $until): void {
                    $builder->whereBetween('finished_at', [$since, $until])
                        ->orWhereBetween('started_at', [$since, $until])
                        ->orWhere(function ($incomplete): void {
                            $incomplete->whereNull('finished_at')->whereNull('started_at');
                        });
                })
                ->latest('started_at')
                ->limit(self::HISTORY_LIMIT);
            $rows = $rows->concat($telemetryQuery->get());
        }

        if ($failedAvailable && ! $telemetryAvailable && (! ($filters['status'] ?? null) || $filters['status'] === 'failed')) {
            $rows = $rows->concat(DB::table((string) config('queue.failed.table', 'failed_jobs'))
                ->whereBetween('failed_at', [$since, $until])
                ->when($queue !== '', fn ($query) => $query->where('queue', $queue))
                ->latest('failed_at')->limit(self::HISTORY_LIMIT)->get()
                ->map(fn (object $job): array => [
                    'job_reference' => $this->hashReference((string) ($job->uuid ?: $job->id), (string) $job->connection, (string) $job->queue),
                    'job_type' => $this->payloadJobType($job->payload),
                    'connection' => (string) $job->connection,
                    'queue' => (string) $job->queue,
                    'status' => 'failed',
                    'attempts' => null,
                    'queued_at' => null,
                    'started_at' => null,
                    'finished_at' => $job->failed_at,
                    'duration_ms' => null,
                ]));
        }

        if ($jobsAvailable && (! ($filters['status'] ?? null) || in_array($filters['status'], ['pending', 'processing'], true))) {
            $jobs = DB::table((string) config('queue.connections.'.config('queue.default').'.table', 'jobs'))
                ->where('queue', $queue)
                ->when($filters['status'] === 'pending', fn ($query) => $query->whereNull('reserved_at'))
                ->when($filters['status'] === 'processing', fn ($query) => $query->whereNotNull('reserved_at'))
                ->whereBetween('created_at', [$since->timestamp, $until->timestamp])
                ->latest('created_at')->limit(self::HISTORY_LIMIT)->get();
            foreach ($jobs as $job) {
                $reference = $this->hashReference((string) $job->id, (string) config('queue.default'), $queue);
                if ($rows->contains(fn ($row) => (string) data_get($row, 'job_reference') === $reference)) {
                    continue;
                }
                $rows->push([
                    'job_reference' => $reference,
                    'job_type' => $this->payloadJobType($job->payload),
                    'connection' => (string) config('queue.default'),
                    'queue' => $queue,
                    'status' => $job->reserved_at === null ? 'pending' : 'processing',
                    'attempts' => (int) $job->attempts,
                    'queued_at' => Carbon::createFromTimestamp((int) $job->created_at)->toIso8601String(),
                    'started_at' => $job->reserved_at ? Carbon::createFromTimestamp((int) $job->reserved_at)->toIso8601String() : null,
                    'finished_at' => null,
                    'duration_ms' => null,
                ]);
            }
        }

        return $rows->sortByDesc(fn ($row) => (string) data_get($row, 'finished_at', data_get($row, 'started_at', data_get($row, 'queued_at'))))->take(self::HISTORY_LIMIT)->map(function ($row): array {
            return [
                'reference' => (string) data_get($row, 'job_reference'),
                'job_type' => (string) data_get($row, 'job_type'),
                'connection' => (string) data_get($row, 'connection'),
                'queue' => (string) data_get($row, 'queue'),
                'status' => (string) data_get($row, 'status'),
                'attempts' => data_get($row, 'attempts'),
                'created_at' => optional(data_get($row, 'queued_at'))->toIso8601String() ?? data_get($row, 'queued_at'),
                'started_at' => optional(data_get($row, 'started_at'))->toIso8601String() ?? data_get($row, 'started_at'),
                'finished_at' => optional(data_get($row, 'finished_at'))->toIso8601String() ?? data_get($row, 'finished_at'),
                'duration_ms' => data_get($row, 'duration_ms'),
            ];
        })->values()->all();
    }

    private function activity(array $filters, Carbon $since, Carbon $until, string $queue, bool $telemetryAvailable): array
    {
        if (! $telemetryAvailable) {
            return [];
        }

        return $this->filteredTelemetry($filters, $since, $until, $queue)
            ->whereIn('status', ['completed', 'failed'])
            ->get(['finished_at', 'status'])
            ->groupBy(fn (PlatformJobTelemetry $row): string => $row->finished_at?->copy()->startOfHour()->toIso8601String() ?? 'unknown')
            ->map(fn ($items, $bucket): array => [
                'bucket' => $bucket,
                'completed' => $items->where('status', 'completed')->count(),
                'failed' => $items->where('status', 'failed')->count(),
            ])->values()->all();
    }

    private function reason(string $driver, bool $jobsAvailable, bool $failedAvailable, bool $telemetryAvailable): string
    {
        if ($driver !== 'database') {
            return "Queue driver '{$driver}' does not expose pending jobs through the database tables.";
        }
        if (! $jobsAvailable) {
            return 'The configured queue jobs table is not available.';
        }
        if (! $failedAvailable) {
            return 'Pending and processing jobs are measurable; failed-job storage is not available.';
        }
        if (! $telemetryAvailable) {
            return 'Queue tables are available; completed duration telemetry is not available until its migration is applied.';
        }

        return 'Queue metrics are based on the configured database queue and bounded lifecycle telemetry.';
    }

    private function reference(Job $job): string
    {
        return $this->hashReference((string) ($job->getJobId() ?: data_get($job->payload(), 'uuid', Str::uuid())), $this->connection($job), (string) $job->getQueue());
    }

    private function hashReference(string $id, string $connection, string $queue): string
    {
        return hash_hmac('sha256', $connection.'|'.$queue.'|'.$id, (string) config('app.key', 'ciinlv-monitoring'));
    }

    private function connection(Job $job): string
    {
        return (string) ($job->getConnectionName() ?: config('queue.default'));
    }

    private function jobType(Job $job): string
    {
        return Str::limit(class_basename((string) $job->resolveName()), 160, '');
    }

    private function queuedAt(Job $job, Carbon $fallback): Carbon
    {
        $pushedAt = data_get($job->payload(), 'pushedAt');

        return is_numeric($pushedAt) ? Carbon::createFromTimestamp((int) $pushedAt) : $fallback;
    }

    private function payloadJobType(?string $payload): string
    {
        $decoded = json_decode((string) $payload, true);
        $displayName = is_array($decoded) ? (string) ($decoded['displayName'] ?? 'UnknownJob') : 'UnknownJob';

        return Str::limit(class_basename($displayName), 160, '');
    }

    private function dateFilter(?string $value, Carbon $fallback): Carbon
    {
        if (! $value) {
            return $fallback;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
