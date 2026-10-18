<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Http\Request;

class BranchReportingService
{
    public function __construct(private readonly SalesAnalyticsService $sales, private readonly ReportScopeService $scope) {}

    public function comparison(Request $request, Tenant $tenant): array
    {
        [$from, $to] = $this->sales->range($tenant, $request->input('from'), $request->input('to'));
        $ids = $this->scope->storeIds($request->user(), $tenant, $request->input('store_id'));
        $stores = Store::whereIn('id', $ids)->get();
        $rows = $stores->map(function (Store $store) use ($tenant, $from, $to): array {
            $sales = Sale::where('tenant_id', $tenant->id)->where('store_id', $store->id)->where('status', 'completed')->whereBetween('completed_at', [$from, $to]);
            $total = (float) (clone $sales)->sum('grand_total');
            $count = (int) (clone $sales)->count();
            $locations = $store->tenant->inventoryLocations()->where('store_id', $store->id)->pluck('id');
            $inventory = (float) InventoryBalance::whereIn('inventory_location_id', $locations)->selectRaw('COALESCE(SUM(on_hand_quantity * average_cost),0) AS value')->value('value');

            return ['store_id' => $store->uuid, 'store_name' => $store->name, 'transaction_count' => $count, 'total_sales' => number_format($total, 2, '.', ''), 'average_order_value' => $count ? number_format($total / $count, 2, '.', '') : '0.00', 'inventory_value' => number_format($inventory, 4, '.', '')];
        });

        return ['basis' => ['sales' => 'completed_at', 'inventory' => 'current weighted-average balances'], 'rows' => $rows->values()];
    }
}
