<?php

return [
    'redis_enabled' => (bool) env('HEALTHCHECK_REDIS', false),

    // A worker or scheduled command older than this is not considered healthy.
    'heartbeat_stale_seconds' => (int) env('HEALTH_HEARTBEAT_STALE_SECONDS', 300),

    // Prevent an idle worker loop from writing a row on every poll.
    'heartbeat_write_interval_seconds' => (int) env('HEALTH_HEARTBEAT_WRITE_INTERVAL_SECONDS', 30),

    // Keep lifecycle telemetry bounded without retaining job payloads.
    'job_telemetry_retention_days' => (int) env('HEALTH_JOB_TELEMETRY_RETENTION_DAYS', 7),

    // Defaults to the application's configured disk; production can opt into
    // a comma-separated list such as "private,public,s3".
    'storage_disks' => array_values(array_filter(array_map(
        static fn (string $disk): string => trim($disk),
        explode(',', (string) env('HEALTHCHECK_STORAGE_DISKS', env('FILESYSTEM_DISK', 'local'))),
    ))),
];
