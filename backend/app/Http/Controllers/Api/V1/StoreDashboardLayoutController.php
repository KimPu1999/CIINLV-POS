<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\UpdateDashboardLayoutRequest;
use App\Http\Requests\Business\UpdateDashboardWidgetsRequest;
use App\Models\Store;
use App\Services\StoreContextService;
use App\Services\StoreDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StoreDashboardLayoutController
{
    public function show(Store $store, StoreContextService $context, StoreDashboardService $service, Request $request): JsonResponse
    {
        $context->store($store);
        Gate::authorize('view', $store);

        return response()->json(['success' => true, 'data' => $service->layout($store, $request->user())]);
    }

    public function update(UpdateDashboardLayoutRequest $request, Store $store, StoreContextService $context, StoreDashboardService $service): JsonResponse
    {
        $context->store($store);
        Gate::authorize('manageDashboard', $store);

        return response()->json(['success' => true, 'data' => $service->updateLayout($store, $request->validated(), $request->user())]);
    }

    public function updateWidgets(UpdateDashboardWidgetsRequest $request, Store $store, StoreContextService $context, StoreDashboardService $service): JsonResponse
    {
        $context->store($store);
        Gate::authorize('manageDashboard', $store);

        return response()->json(['success' => true, 'data' => $service->updateWidgets($store, $request->validated(), $request->user())]);
    }
}
