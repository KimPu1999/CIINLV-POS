<?php

namespace App\Services;

use App\Models\PlatformSchedulerTelemetry;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PlatformSchedulerTelemetryService
{
    private const HISTORY_LIMIT = 100;

    /**
     * The scheduler command emits lifecycle events in one process. This map
     * correlates a start event with its finish/failure event without persisting
     * command arguments, output, or other sensitive process data.
     *
     * @var array<int, string>
     */
    private array $activeExecutions = [];

    public function recordStarting(ScheduledTaskStarting $event): void
    {
        try {
            if (! $this->available()) {
                return;
            }

            $metadata = $this->metadata($event->task);
            $reference = $this->reference();
            $this->activeExecutions[spl_object_id($event->task)] = $reference;

            PlatformSchedulerTelemetry::query()->create([
                ...$metadata,
                'execution_reference' => $reference,
                'status' => 'running',
                'started_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function recordFinished(ScheduledTaskFinished $event): void
    {
        if ((int) $event->task->exitCode !== 0) {
            // Laravel emits ScheduledTaskFinished before ScheduledTaskFailed
            // when a command exits non-zero. Wait for the failure event so a
            // failed command cannot be counted as a success.
            return;
        }

        $this->recordCompleted($event->task, $event->runtime, null);
    }

    public function recordFailed(ScheduledTaskFailed $event): void
    {
        $this->recordCompleted($event->task, $event->runtime, $event->exception);
    }

    public function recordSkipped(ScheduledTaskSkipped $event): void
    {
        try {
            if (! $this->available()) {
                return;
            }

            PlatformSchedulerTelemetry::query()->create([
                ...$this->metadata($event->task),
                'execution_reference' => $this->reference(),
                'status' => 'skipped',
                'finished_at' => now(),
                'duration_ms' => 0,
                'error_summary' => 'The scheduler skipped this run because its conditions or overlap lock prevented execution.',
            ]);
            $this->prune();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function available(): bool
    {
        try {
            return Schema::hasTable('platform_scheduler_telemetry');
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function summary(array $filters = [], ?array $schedulerHeartbeat = null): array
    {
        $tasks = $this->registeredTasks();
        $storageAvailable = $this->available();

        if (! $storageAvailable) {
            return [
                'storage_available' => false,
                'registered_task_count' => count($tasks),
                'running_task_count' => null,
                'successful_executions_last_24h' => null,
                'failed_executions_last_24h' => null,
                'missed_or_overdue_count' => null,
                'tasks' => $this->notConfiguredTasks($tasks),
                'history' => [],
                'last_errors' => [],
                'reason' => 'Scheduler telemetry storage is not available; the reviewed migration is pending.',
            ];
        }

        try {
            $now = now();
            $taskFilter = trim((string) ($filters['task'] ?? ''));
            $statusFilter = trim((string) ($filters['scheduler_status'] ?? ''));
            $from = $this->dateFilter($filters['scheduler_from'] ?? $filters['from'] ?? null, $now->copy()->subDay());
            $until = $this->dateFilter($filters['scheduler_to'] ?? $filters['to'] ?? null, $now)->endOfDay();
            $lastDay = $now->copy()->subDay();
            $query = PlatformSchedulerTelemetry::query()
                ->when($taskFilter !== '', fn ($builder) => $builder->where(function ($nested) use ($taskFilter): void {
                    $nested->where('task_name', 'ilike', '%'.$taskFilter.'%')
                        ->orWhere('command_identifier', 'ilike', '%'.$taskFilter.'%');
                }))
                ->when($statusFilter !== '', fn ($builder) => $builder->where('status', $statusFilter));

            $taskRows = $this->taskRows($tasks, $schedulerHeartbeat);
            if ($statusFilter !== '') {
                $taskRows = array_values(array_filter($taskRows, fn (array $task): bool => $task['status'] === $statusFilter));
            }
            if ($taskFilter !== '') {
                $taskRows = array_values(array_filter($taskRows, fn (array $task): bool => str_contains(strtolower($task['task_name'].' '.$task['command_identifier']), strtolower($taskFilter))));
            }

            $history = $query
                ->where(function ($nested) use ($from, $until): void {
                    $nested->whereBetween('finished_at', [$from, $until])
                        ->orWhereBetween('started_at', [$from, $until]);
                })
                ->latest('started_at')
                ->limit(self::HISTORY_LIMIT)
                ->get()
                ->map(fn (PlatformSchedulerTelemetry $row): array => $this->historyRow($row))
                ->values()
                ->all();

            $lastErrors = PlatformSchedulerTelemetry::query()
                ->where('status', 'failed')
                ->when($taskFilter !== '', fn ($builder) => $builder->where(function ($nested) use ($taskFilter): void {
                    $nested->where('task_name', 'ilike', '%'.$taskFilter.'%')
                        ->orWhere('command_identifier', 'ilike', '%'.$taskFilter.'%');
                }))
                ->latest('finished_at')
                ->limit(10)
                ->get()
                ->map(fn (PlatformSchedulerTelemetry $row): array => [
                    'task_name' => $row->task_name,
                    'command_identifier' => $row->command_identifier,
                    'failed_at' => $row->finished_at?->toIso8601String(),
                    'error_summary' => $row->error_summary,
                ])
                ->values()
                ->all();

            $lastDayQuery = PlatformSchedulerTelemetry::query()->whereBetween('finished_at', [$lastDay, $now]);
            if ($taskFilter !== '') {
                $lastDayQuery->where(function ($nested) use ($taskFilter): void {
                    $nested->where('task_name', 'ilike', '%'.$taskFilter.'%')
                        ->orWhere('command_identifier', 'ilike', '%'.$taskFilter.'%');
                });
            }

            return [
                'storage_available' => true,
                'registered_task_count' => count($tasks),
                'running_task_count' => count(array_filter($taskRows, fn (array $task): bool => $task['status'] === 'running')),
                'successful_executions_last_24h' => (clone $lastDayQuery)->where('status', 'completed')->count(),
                'failed_executions_last_24h' => (clone $lastDayQuery)->where('status', 'failed')->count(),
                'missed_or_overdue_count' => count(array_filter($taskRows, fn (array $task): bool => in_array($task['status'], ['missed', 'stale'], true))),
                'tasks' => $taskRows,
                'history' => $history,
                'last_errors' => $lastErrors,
                'reason' => 'Scheduler task metrics are based on Laravel scheduler lifecycle events and the bounded telemetry history.',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'storage_available' => false,
                'registered_task_count' => count($tasks),
                'running_task_count' => null,
                'successful_executions_last_24h' => null,
                'failed_executions_last_24h' => null,
                'missed_or_overdue_count' => null,
                'tasks' => $this->notConfiguredTasks($tasks),
                'history' => [],
                'last_errors' => [],
                'reason' => 'Scheduler telemetry could not be read.',
            ];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function registeredTasks(): array
    {
        try {
            return collect(app(Schedule::class)->events())
                ->map(fn (Event $event): array => $this->metadata($event) + [
                    'task_key' => $this->taskKey($event),
                    'next_due_at' => $this->nextDueAt($event),
                    'without_overlapping' => (bool) $event->withoutOverlapping,
                    'maintenance_mode_allowed' => $event->runsInMaintenanceMode(),
                ])
                ->values()
                ->all();
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    private function recordCompleted(Event $task, ?float $runtime, ?Throwable $error): void
    {
        try {
            if (! $this->available()) {
                return;
            }

            $reference = $this->activeExecutions[spl_object_id($task)] ?? null;
            $row = $reference
                ? PlatformSchedulerTelemetry::query()->where('execution_reference', $reference)->first()
                : PlatformSchedulerTelemetry::query()
                    ->where('task_key', $this->taskKey($task))
                    ->where('status', 'running')
                    ->latest('started_at')
                    ->first();
            $finishedAt = now();
            $message = $error?->getMessage();

            if ($row) {
                $row->update([
                    'status' => $error ? 'failed' : 'completed',
                    'finished_at' => $finishedAt,
                    'duration_ms' => $runtime === null
                        ? ($row->started_at ? max(0, $row->started_at->diffInMilliseconds($finishedAt)) : null)
                        : max(0, (int) round($runtime * 1000)),
                    'error_summary' => $error ? $this->safeError($message) : null,
                ]);
            } else {
                PlatformSchedulerTelemetry::query()->create([
                    ...$this->metadata($task),
                    'execution_reference' => $this->reference(),
                    'status' => $error ? 'failed' : 'completed',
                    'started_at' => $runtime === null ? null : $finishedAt->copy()->subMilliseconds((int) round($runtime * 1000)),
                    'finished_at' => $finishedAt,
                    'duration_ms' => $runtime === null ? null : max(0, (int) round($runtime * 1000)),
                    'error_summary' => $error ? $this->safeError($message) : null,
                ]);
            }

            unset($this->activeExecutions[spl_object_id($task)]);
            $this->prune();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function metadata(Event $event): array
    {
        $command = $this->commandIdentifier($event);

        return [
            'task_key' => $this->taskKey($event),
            'task_name' => Str::limit((string) ($event->description ?: $command), 160, ''),
            'command_identifier' => Str::limit($command, 160, ''),
            'schedule_frequency' => $this->frequency($event->getExpression()),
            'schedule_expression' => Str::limit((string) $event->getExpression(), 100, ''),
            'timezone' => $event->timezone instanceof \DateTimeZone
                ? $event->timezone->getName()
                : ($event->timezone ? (string) $event->timezone : config('app.schedule_timezone', config('app.timezone'))),
        ];
    }

    private function taskRows(array $tasks, ?array $schedulerHeartbeat): array
    {
        $heartbeatStatus = (string) data_get($schedulerHeartbeat, 'status', 'unknown');
        $grace = max(0, (int) config('health.scheduler_missed_grace_seconds', 120));

        return collect($tasks)->map(function (array $task) use ($heartbeatStatus, $grace): array {
            $latest = PlatformSchedulerTelemetry::query()
                ->where('task_key', $task['task_key'])
                ->latest('started_at')
                ->first();
            $lastSuccess = PlatformSchedulerTelemetry::query()
                ->where('task_key', $task['task_key'])
                ->where('status', 'completed')
                ->latest('finished_at')
                ->first();
            $lastFailure = PlatformSchedulerTelemetry::query()
                ->where('task_key', $task['task_key'])
                ->where('status', 'failed')
                ->latest('finished_at')
                ->first();
            $status = $this->taskStatus($latest, $task, $heartbeatStatus, $grace);

            return [
                'task_name' => $task['task_name'],
                'command_identifier' => $task['command_identifier'],
                'schedule_frequency' => $task['schedule_frequency'],
                'schedule_expression' => $task['schedule_expression'],
                'timezone' => $task['timezone'],
                'last_started_at' => $latest?->started_at?->toIso8601String(),
                'last_finished_at' => $latest?->finished_at?->toIso8601String(),
                'last_success_at' => $lastSuccess?->finished_at?->toIso8601String(),
                'last_failure_at' => $lastFailure?->finished_at?->toIso8601String(),
                'next_due_at' => $task['next_due_at'],
                'execution_duration_ms' => $latest?->duration_ms,
                'current_status' => $status,
                'status' => $status,
                'error_summary' => $lastFailure?->error_summary,
                'without_overlapping' => $task['without_overlapping'],
                'maintenance_mode_allowed' => $task['maintenance_mode_allowed'],
            ];
        })->values()->all();
    }

    private function taskStatus(?PlatformSchedulerTelemetry $latest, array $task, string $heartbeatStatus, int $grace): string
    {
        if (! $latest) {
            return 'unknown';
        }
        if ($latest->status === 'running') {
            return 'running';
        }
        if ($latest->status === 'failed') {
            return 'failed';
        }
        if ($latest->status === 'skipped') {
            return 'missed';
        }

        try {
            $previousDue = $this->previousDueAt($task['schedule_expression'], $task['timezone']);
            if ($previousDue && $latest->finished_at && $latest->finished_at->lt($previousDue->copy()->subSeconds($grace))) {
                return in_array($heartbeatStatus, ['stale', 'unhealthy'], true) ? 'stale' : 'missed';
            }
            if (in_array($heartbeatStatus, ['stale', 'unhealthy'], true)) {
                return 'stale';
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return 'healthy';
    }

    private function historyRow(PlatformSchedulerTelemetry $row): array
    {
        return [
            'reference' => $row->execution_reference,
            'task_name' => $row->task_name,
            'command_identifier' => $row->command_identifier,
            'schedule_frequency' => $row->schedule_frequency,
            'status' => $row->status,
            'started_at' => $row->started_at?->toIso8601String(),
            'finished_at' => $row->finished_at?->toIso8601String(),
            'duration_ms' => $row->duration_ms,
            'error_summary' => $row->error_summary,
        ];
    }

    private function notConfiguredTasks(array $tasks): array
    {
        return array_map(fn (array $task): array => [
            'task_name' => $task['task_name'],
            'command_identifier' => $task['command_identifier'],
            'schedule_frequency' => $task['schedule_frequency'],
            'schedule_expression' => $task['schedule_expression'],
            'timezone' => $task['timezone'],
            'last_started_at' => null,
            'last_finished_at' => null,
            'last_success_at' => null,
            'last_failure_at' => null,
            'next_due_at' => $task['next_due_at'],
            'execution_duration_ms' => null,
            'current_status' => 'not_configured',
            'status' => 'not_configured',
            'error_summary' => null,
            'without_overlapping' => $task['without_overlapping'],
            'maintenance_mode_allowed' => $task['maintenance_mode_allowed'],
        ], $tasks);
    }

    private function commandIdentifier(Event $event): string
    {
        $command = trim((string) ($event->command ?: $event->getSummaryForDisplay()));
        if (preg_match("/'artisan'\\s+([^\\s]+)/", $command, $matches) === 1) {
            return (string) $matches[1];
        }

        $command = preg_replace('/^.*?artisan\s+/', '', $command) ?: $command;

        return Str::before(trim($command), ' ');
    }

    private function taskKey(Event $event): string
    {
        return hash_hmac('sha256', $this->commandIdentifier($event).'|'.$event->getExpression().'|'.($event->timezone ?: ''), (string) config('app.key', 'ciinlv-monitoring'));
    }

    private function nextDueAt(Event $event): ?string
    {
        try {
            return $event->nextRunDate(now())->toIso8601String();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function previousDueAt(string $expression, ?string $timezone): ?Carbon
    {
        $cron = new CronExpression($expression);

        return Carbon::instance($cron->getPreviousRunDate(now(), 0, false, $timezone ?: config('app.schedule_timezone', config('app.timezone'))));
    }

    private function frequency(string $expression): string
    {
        return match ($expression) {
            '* * * * *' => 'Every minute',
            '0 * * * *' => 'Hourly',
            '0 2 * * *' => 'Daily at 02:00',
            default => $expression,
        };
    }

    private function safeError(?string $message): ?string
    {
        if (! $message) {
            return 'The scheduled task failed.';
        }

        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/\b(password|secret|token|api[_-]?key|authorization)\s*[=:]\s*\S+/i', '$1=[redacted]', $message) ?? $message;

        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $message)), 1000, '…');
    }

    private function reference(): string
    {
        return hash_hmac('sha256', (string) Str::uuid(), (string) config('app.key', 'ciinlv-monitoring'));
    }

    private function prune(): void
    {
        PlatformSchedulerTelemetry::query()
            ->where('created_at', '<', now()->subDays((int) config('health.scheduler_telemetry_retention_days', 30)))
            ->delete();
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
}
