<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class DatabaseHealthCheck
{
    /**
     * Readiness checks deliberately expose state, not connection details or credentials.
     * Redis is optional locally but can be made required in staging/production.
     *
     * @return array{healthy: bool, checks: array<string, array{status: string, message?: string}>}
     */
    public function run(): array
    {
        $checks = [];

        try {
            DB::selectOne('select 1 as ready');
            $checks['database'] = ['status' => 'ok'];
        } catch (Throwable $exception) {
            report($exception);
            $checks['database'] = ['status' => 'failed', 'message' => 'Database connection unavailable.'];
        }

        if (config('health.redis_enabled', false)) {
            try {
                Redis::connection()->ping();
                $checks['redis'] = ['status' => 'ok'];
            } catch (Throwable $exception) {
                report($exception);
                $checks['redis'] = ['status' => 'failed', 'message' => 'Redis connection unavailable.'];
            }
        } else {
            $checks['redis'] = ['status' => 'not_required'];
        }

        $healthy = collect($checks)->every(fn (array $check): bool => in_array($check['status'], ['ok', 'not_required'], true));

        return compact('healthy', 'checks');
    }
}
