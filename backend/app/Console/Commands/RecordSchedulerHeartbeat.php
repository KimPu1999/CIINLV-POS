<?php

namespace App\Console\Commands;

use App\Services\PlatformHealthHeartbeatService;
use Illuminate\Console\Command;
use Throwable;

class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'ciinlv:scheduler-heartbeat';

    protected $description = 'Persist evidence that the Laravel scheduler executed.';

    public function handle(PlatformHealthHeartbeatService $heartbeats): int
    {
        try {
            if (! $heartbeats->available()) {
                $this->warn('Health heartbeat storage is not available; scheduler evidence was not persisted.');

                return self::SUCCESS;
            }

            if (! $heartbeats->recordSuccess(PlatformHealthHeartbeatService::SCHEDULER, [
                'source' => 'scheduled_command',
            ])) {
                $this->error('The scheduler heartbeat could not be persisted.');

                return self::FAILURE;
            }

            $this->info('Scheduler heartbeat recorded.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $heartbeats->recordFailure(PlatformHealthHeartbeatService::SCHEDULER, $exception, [
                'source' => 'scheduled_command',
            ]);
            $this->error('The scheduler heartbeat failed.');

            return self::FAILURE;
        }
    }
}
