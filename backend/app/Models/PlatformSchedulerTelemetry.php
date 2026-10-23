<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSchedulerTelemetry extends Model
{
    protected $table = 'platform_scheduler_telemetry';

    protected $fillable = [
        'execution_reference',
        'task_key',
        'task_name',
        'command_identifier',
        'schedule_frequency',
        'schedule_expression',
        'timezone',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'error_summary',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }
}
