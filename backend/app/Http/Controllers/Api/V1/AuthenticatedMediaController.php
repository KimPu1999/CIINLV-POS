<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AuthenticatedMediaController
{
    public function profileImage(Request $request)
    {
        $path = $request->user()->profile_image_path;

        abort_unless($path && Storage::disk('private')->exists($path), 404);

        return response()->file(Storage::disk('private')->path($path), [
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function businessLogo(Request $request, Tenant $tenant)
    {
        abort_unless($request->user()->tenants()
            ->whereKey($tenant->id)
            ->wherePivot('status', 'active')
            ->where('tenants.status', 'active')
            ->exists(), 403);

        $path = $tenant->logo_path;
        abort_unless($path && Storage::disk('private')->exists($path), 404);

        return response()->file(Storage::disk('private')->path($path), [
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
