<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\BusinessLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PlatformBusinessLifecycleController
{
    public function archive(Tenant $business, Request $request, BusinessLifecycleService $service): JsonResponse
    {
        Gate::authorize('manage', $business);

        return response()->json(['success' => true, 'message' => 'Business archived.', 'data' => new TenantResource($service->archive($business, $request->user()))]);
    }

    public function restore(Tenant $business, Request $request, BusinessLifecycleService $service): JsonResponse
    {
        Gate::authorize('manage', $business);

        return response()->json(['success' => true, 'message' => 'Business restored.', 'data' => new TenantResource($service->restore($business, $request->user()))]);
    }
}
