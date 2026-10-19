<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\InventoryBalance;
use App\Models\Sale;
use App\Services\DashboardMetricsService;
use App\Services\InventoryValuationService;
use App\Services\ReportScopeService;
use App\Services\SalesAnalyticsService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardOverviewController
{
    public function overview(Request $request, TenantContext $context, DashboardMetricsService $metrics): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $metrics->overview($request, $context->tenant())]);
    }

    public function salesSummary(Request $request, TenantContext $context, SalesAnalyticsService $analytics): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $analytics->summary($request, $context->tenant())]);
    }

    public function inventorySummary(Request $request, TenantContext $context, InventoryValuationService $valuation): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $valuation->current($request, $context->tenant())]);
    }

    public function lowStock(Request $request, TenantContext $context): JsonResponse
    {
        $locations = app(ReportScopeService::class)->locationIds($request->user(), $context->tenant(), $request->input('store_id'));
        $query = InventoryBalance::with(['location', 'variant.product'])
            ->join('product_variants', function ($join): void {
                $join->on('product_variants.id', '=', 'inventory_balances.product_variant_id')
                    ->on('product_variants.tenant_id', '=', 'inventory_balances.tenant_id');
            })
            ->where('inventory_balances.tenant_id', $context->tenant()->id)
            ->whereIn('inventory_balances.inventory_location_id', $locations)
            ->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level')
            ->select('inventory_balances.*');

        return response()->json(['success' => true, 'data' => $query->paginate(50)]);
    }

    public function recentSales(Request $request, TenantContext $context): JsonResponse
    {
        $stores = app(ReportScopeService::class)->storeIds($request->user(), $context->tenant(), $request->input('store_id'));
        $rows = Sale::with(['store', 'cashier'])->where('tenant_id', $context->tenant()->id)->whereIn('store_id', $stores)->where('status', 'completed')->latest('completed_at')->paginate(20);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function topProducts(Request $request, TenantContext $context, SalesAnalyticsService $analytics): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $analytics->topProducts($request, $context->tenant())]);
    }

    public function salesTrend(Request $request, TenantContext $context, SalesAnalyticsService $analytics): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $analytics->trend($request, $context->tenant())]);
    }
}
