<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SaleItem;
use App\Models\Store;
use App\Services\BranchReportingService;
use App\Services\ReportScopeService;
use App\Services\SalesAnalyticsService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfitReportController
{
    public function summary(Request $request, TenantContext $context, SalesAnalyticsService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->summary($request, $context->tenant())]);
    }

    public function byProduct(Request $request, TenantContext $context, SalesAnalyticsService $service): JsonResponse
    {
        [$from, $to] = $service->range($context->tenant(), $request->input('from'), $request->input('to'));
        $stores = app(ReportScopeService::class)->storeIds($request->user(), $context->tenant(), $request->input('store_id'));
        $rows = SaleItem::with('variant.product')->whereHas('sale', fn ($q) => $q->where('tenant_id', $context->tenant()->id)->whereIn('store_id', $stores)->where('status', 'completed')->whereBetween('completed_at', [$from, $to]))->selectRaw('product_variant_id, SUM(line_total - tax_amount) AS net_sales, SUM(quantity * unit_cost_at_sale) AS cogs, SUM((line_total - tax_amount) - (quantity * unit_cost_at_sale)) AS gross_profit')->groupBy('product_variant_id')->orderByDesc('gross_profit')->paginate(50);

        return response()->json(['success' => true, 'data' => ['basis' => 'completed_at; historical unit_cost_at_sale', 'rows' => $rows]]);
    }

    public function byBranch(Request $request, TenantContext $context, SalesAnalyticsService $service, ?Store $store = null): JsonResponse
    {
        if ($store) {
            abort_unless((int) $store->tenant_id === (int) $context->tenant()->id, 404);
            $request->merge(['store_id' => $store->uuid]);
        }

        return response()->json(['success' => true, 'data' => app(BranchReportingService::class)->comparison($request, $context->tenant())]);
    }
}
