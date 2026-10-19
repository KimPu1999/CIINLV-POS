<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class BackupMonitoringService
{
    /** @return array{path: string, file_count: int, latest_at: ?int, healthy: bool} */
    public function inspect(?string $path = null): array
    {
        $path ??= (string) env('BACKUP_DIR', storage_path('app/private/backups'));
        if (! File::isDirectory($path)) {
            return ['path' => $path, 'file_count' => 0, 'latest_at' => null, 'healthy' => false];
        }

        $files = collect(File::allFiles($path))->filter(fn ($file) => $file->isFile());
        $latest = $files->max(fn ($file) => $file->getMTime());

        return [
            'path' => $path,
            'file_count' => $files->count(),
            'latest_at' => $latest === false ? null : (int) $latest,
            'healthy' => $files->isNotEmpty(),
        ];
    }
}
