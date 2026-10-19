<?php

namespace App\Console\Commands;

use App\Services\SecurityAuditService;
use Illuminate\Console\Command;

class SecurityAudit extends Command
{
    protected $signature = 'ciinlv:security-audit';

    protected $description = 'Run a read-only application and PostgreSQL security posture audit.';

    public function handle(SecurityAuditService $audit): int
    {
        $this->line(json_encode($audit->run(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
