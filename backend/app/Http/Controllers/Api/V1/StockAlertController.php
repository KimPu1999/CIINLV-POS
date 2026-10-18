<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AlertPreference;
use App\Models\StockAlert;
use App\Services\ReportScopeService;
use App\Services\StockAlertService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAlertController
{
    public function index(Request $request, TenantContext $context, ReportScopeService $scope): JsonResponse
    {
        $rows = StockAlert::with(['location', 'variant.product'])->where('tenant_id', $context->tenant()->id)->whereIn('inventory_location_id', $scope->locationIds($request->user(), $context->tenant(), $request->input('store_id')))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->latest('last_triggered_at')->paginate(50);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function unreadCount(Request $request, TenantContext $context, ReportScopeService $scope): JsonResponse
    {
        $count = StockAlert::where('tenant_id', $context->tenant()->id)->whereIn('inventory_location_id', $scope->locationIds($request->user(), $context->tenant()))->where('status', 'open')->count();

        return response()->json(['success' => true, 'data' => ['count' => $count]]);
    }

    public function acknowledge(StockAlert $alert, Request $request, TenantContext $context, StockAlertService $service, ReportScopeService $scope): JsonResponse
    {
        abort_unless((int) $alert->tenant_id === (int) $context->tenant()->id && $scope->locationIds($request->user(), $context->tenant())->contains($alert->inventory_location_id), 403);

        return response()->json(['success' => true, 'data' => $service->acknowledge($alert, $request->user())]);
    }

    public function preferences(Request $request, TenantContext $context): JsonResponse
    {
        $preference = AlertPreference::firstOrCreate(['tenant_id' => $context->tenant()->id, 'user_id' => $request->user()->id]);

        return response()->json(['success' => true, 'data' => $preference]);
    }

    public function updatePreferences(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate(['email_enabled' => ['sometimes', 'boolean'], 'database_enabled' => ['sometimes', 'boolean'], 'low_stock_enabled' => ['sometimes', 'boolean'], 'out_of_stock_enabled' => ['sometimes', 'boolean'], 'channels' => ['sometimes', 'array', 'max:10']]);
        $preference = AlertPreference::updateOrCreate(['tenant_id' => $context->tenant()->id, 'user_id' => $request->user()->id], $data);

        return response()->json(['success' => true, 'data' => $preference]);
    }
}
