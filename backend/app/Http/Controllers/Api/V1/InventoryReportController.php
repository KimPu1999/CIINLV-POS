<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StoreMembership;
use App\Services\InventoryValuationService;
use App\Services\ReportScopeService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class InventoryReportController
{
    public function valuationDetail(Request $request, TenantContext $context, InventoryValuationService $valuation): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $valuation->current($request, $context->tenant())]);
    }

    public function byLocation(Request $request, TenantContext $context, InventoryValuationService $valuation): JsonResponse
    {
        $data = $valuation->current($request, $context->tenant());
        $rows = collect($data['rows'])->groupBy('location_id')->map(fn ($items, $location) => ['location_id' => $location, 'location_name' => $items->first()['location_name'], 'value' => number_format($items->sum(fn ($row) => (float) $row['value']), 4, '.', '')])->values();

        return response()->json(['success' => true, 'data' => ['costing_method' => $data['costing_method'], 'rows' => $rows]]);
    }

    public function movementsDetail(Request $request, TenantContext $context): JsonResponse
    {
        return $this->movements($request, $context);
    }

    public function lowStockDetail(Request $request, TenantContext $context, ReportScopeService $scope): JsonResponse
    {
        return $this->stockListForReport($request, $context, $scope, false);
    }

    public function outOfStockDetail(Request $request, TenantContext $context, ReportScopeService $scope): JsonResponse
    {
        return $this->stockListForReport($request, $context, $scope, true);
    }

    public function inventory(Request $request, TenantContext $context): JsonResponse
    {
        $rows = InventoryBalance::with(['location.store', 'variant.product'])->where('tenant_id', $context->tenant()->id)->whereIn('inventory_location_id', $this->locationIds($request->user(), $context->tenant()->id))->paginate(100);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function movements(Request $request, TenantContext $context): JsonResponse
    {
        $rows = StockMovement::with(['location', 'variant.product', 'actor'])->where('tenant_id', $context->tenant()->id)->whereIn('inventory_location_id', $this->locationIds($request->user(), $context->tenant()->id))->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))->latest('created_at')->paginate(100);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function transfers(Request $request, TenantContext $context): JsonResponse
    {
        $rows = StockTransfer::with(['source', 'destination', 'items.variant'])->where('tenant_id', $context->tenant()->id)->where(function ($query) use ($request, $context): void {
            $ids = $this->locationIds($request->user(), $context->tenant()->id);
            $query->whereIn('source_location_id', $ids)->orWhereIn('destination_location_id', $ids);
        })->latest()->paginate(50);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function valuation(Request $request, TenantContext $context): JsonResponse
    {
        $rows = InventoryBalance::with(['location', 'variant.product'])->where('tenant_id', $context->tenant()->id)->whereIn('inventory_location_id', $this->locationIds($request->user(), $context->tenant()->id))->get();
        $total = $rows->reduce(fn ($carry, InventoryBalance $balance) => $carry + ((float) $balance->on_hand_quantity * (float) $balance->average_cost), 0.0);

        return response()->json(['success' => true, 'data' => ['costing_method' => 'weighted_average', 'total_value' => number_format($total, 4, '.', ''), 'rows' => $rows]]);
    }

    private function locationIds($user, int $tenantId): Collection
    {
        $role = $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->with('role')->first()?->role?->name;
        if ($role === 'business_admin') {
            return InventoryLocation::where('tenant_id', $tenantId)->pluck('id');
        }
        $storeIds = StoreMembership::where('tenant_id', $tenantId)->where('user_id', $user->id)->where('status', 'active')->whereNull('revoked_at')->pluck('store_id');

        return InventoryLocation::where('tenant_id', $tenantId)->where(function ($query) use ($role, $storeIds): void {
            $query->whereIn('store_id', $storeIds);
            if ($role === 'inventory_manager') {
                $query->orWhere('location_type', 'warehouse');
            }
        })->pluck('id');
    }

    private function stockListForReport(Request $request, TenantContext $context, ReportScopeService $scope, bool $out): JsonResponse
    {
        $ids = $scope->locationIds($request->user(), $context->tenant(), $request->input('store_id'));
        $query = InventoryBalance::with(['location', 'variant.product'])->join('product_variants', 'product_variants.id', '=', 'inventory_balances.product_variant_id')->where('inventory_balances.tenant_id', $context->tenant()->id)->whereIn('inventory_location_id', $ids)->select('inventory_balances.*')->when($out, fn ($q) => $q->whereRaw('(on_hand_quantity - reserved_quantity) <= 0'), fn ($q) => $q->whereRaw('(on_hand_quantity - reserved_quantity) <= product_variants.reorder_level'));

        return response()->json(['success' => true, 'data' => $query->paginate(50)]);
    }
}
