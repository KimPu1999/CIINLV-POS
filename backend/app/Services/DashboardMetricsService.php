<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardMetricsService
{
    public function __construct(private readonly SalesAnalyticsService $sales, private readonly InventoryValuationService $valuation) {}

    public function overview(Request $request, Tenant $tenant): array
    {
        $stores = app(ReportScopeService::class)->storeIds($request->user(), $tenant, $request->input('store_id'))->sort()->values()->implode(',');
        $key = 'ciinlv:report:'.$tenant->id.':'.$stores.':overview:v'.Cache::get($this->versionKey($tenant->id), 1).':'.sha1((string) $request->query('from').'|'.$request->query('to').'|'.$tenant->businessSetting?->timezone);

        return Cache::remember($key, now()->addSeconds(30), function () use ($request, $tenant): array {
            $summary = $this->sales->summary($request, $tenant);
            $valuation = $this->valuation->current($request, $tenant);
            $locations = app(ReportScopeService::class)->locationIds($request->user(), $tenant, $request->input('store_id'));
            $low = InventoryBalance::where('inventory_balances.tenant_id', $tenant->id)->whereIn('inventory_balances.inventory_location_id', $locations)->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= (SELECT product_variants.reorder_level FROM product_variants WHERE product_variants.id = inventory_balances.product_variant_id AND product_variants.tenant_id = inventory_balances.tenant_id)')->count();
            $out = InventoryBalance::where('tenant_id', $tenant->id)->whereIn('inventory_location_id', $locations)->whereRaw('(on_hand_quantity - reserved_quantity) <= 0')->count();

            return ['currency' => $summary['currency'], 'total_sales' => $summary['gross_sales'], 'total_transactions' => $summary['transaction_count'], 'gross_profit' => $summary['gross_profit'], 'low_stock_products' => $low, 'out_of_stock_products' => $out, 'inventory_value' => $valuation['total_value'], 'last_updated_at' => now()->toIso8601String(), 'accounting_basis' => $summary['basis']];
        });
    }

    public function invalidate(int $tenantId): void
    {
        Cache::put($this->versionKey($tenantId), (int) Cache::get($this->versionKey($tenantId), 1) + 1, now()->addDay());
    }

    private function versionKey(int $tenantId): string
    {
        return 'ciinlv:report:'.$tenantId.':version';
    }
}
