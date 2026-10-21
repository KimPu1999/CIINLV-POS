<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PublicMediaUrl
{
    public static function make(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $url = Storage::disk('public')->url($path);

        // S3 and a public disk configured with APP_URL already return an
        // absolute URL. Local disks may return /storage/... instead; resolve
        // that against Laravel's configured application URL rather than a
        // frontend origin or a hard-coded localhost address.
        return Str::startsWith($url, ['http://', 'https://', '//'])
            ? $url
            : url('/'.ltrim($url, '/'));
    }
}
