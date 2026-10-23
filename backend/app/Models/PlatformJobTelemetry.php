<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformJobTelemetry extends Model
{
    protected $table = 'platform_job_telemetry';

    protected $fillable = [
        'job_reference',
        'job_type',
        'connection',
        'queue',
        'status',
        'attempts',
        'queued_at',
        'started_at',
        'finished_at',
        'duration_ms',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'attempts' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
