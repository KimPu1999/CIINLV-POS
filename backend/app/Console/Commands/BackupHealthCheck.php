<?php

namespace App\Console\Commands;

use App\Services\BackupMonitoringService;
use Illuminate\Console\Command;

class BackupHealthCheck extends Command
{
    protected $signature = 'ciinlv:backup-health {--path= : Backup directory to inspect}';

    protected $description = 'Check that the configured backup directory exists and contains recent artifacts.';

    public function handle(BackupMonitoringService $monitor): int
    {
        $result = $monitor->inspect($this->option('path') ?: null);
        if (! $result['healthy']) {
            $this->error("Backup directory is missing or empty: {$result['path']}");

            return self::FAILURE;
        }

        $this->info("Backup directory is readable; {$result['file_count']} file(s) found.");

        return self::SUCCESS;
    }
}
