<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Store;
use App\Services\StoreContextService;
use App\Services\StoreDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StoreDashboardController
{
    public function show(Store $store, StoreContextService $context, StoreDashboardService $service, Request $request): JsonResponse
    {
        $context->store($store);
        Gate::authorize('view', $store);
        $permissions = $request->user()->isSuperAdmin() ? ['store.view', 'dashboard.view'] : ['store.view', 'dashboard.view'];

        return response()->json(['success' => true, 'data' => ['store' => ['id' => $store->uuid, 'name' => $store->name, 'status' => $store->status], 'user_permissions' => $permissions, 'widgets' => $service->widgets($store, $request->user()), 'metrics_status' => 'not_implemented']]);
    }

    public function widgets(Store $store, StoreContextService $context, StoreDashboardService $service, Request $request): JsonResponse
    {
        $context->store($store);
        Gate::authorize('view', $store);

        return response()->json(['success' => true, 'data' => $service->widgets($store, $request->user())]);
    }
}
