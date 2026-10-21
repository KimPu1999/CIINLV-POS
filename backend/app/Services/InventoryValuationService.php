<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\SaleItem;
use App\Models\Tenant;
use Illuminate\Http\Request;

class InventoryValuationService
{
    public function __construct(private readonly ReportScopeService $scope) {}

    public function current(Request $request, Tenant $tenant): array
    {
        $locations = $this->scope->locationIds($request->user(), $tenant, $request->input('store_id'));
        $balances = InventoryBalance::with(['location.store', 'variant.product.category'])->where('tenant_id', $tenant->id)->whereIn('inventory_location_id', $locations)->get();
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);
        $rows = $balances->map(function (InventoryBalance $balance) use ($translations, $locale): array {
            $value = (float) $balance->on_hand_quantity * (float) $balance->average_cost;

            return ['location_id' => $balance->location?->uuid, 'location_name' => $balance->location ? $translations->localizedValue($balance->location, 'name', $locale) : null, 'store_id' => $balance->location?->store?->uuid, 'product_variant_id' => $balance->variant?->uuid, 'sku' => $balance->variant?->sku, 'product_name' => $balance->variant?->product ? $translations->localizedValue($balance->variant->product, 'name', $locale) : null, 'on_hand_quantity' => (string) $balance->on_hand_quantity, 'reserved_quantity' => (string) $balance->reserved_quantity, 'available_quantity' => $balance->available_quantity, 'average_cost' => number_format((float) $balance->average_cost, 4, '.', ''), 'value' => number_format($value, 4, '.', '')];
        });

        return ['costing_method' => 'weighted_average', 'basis' => 'current inventory_balances.on_hand_quantity multiplied by average_cost; reserved stock remains owned inventory', 'historical_as_of' => ['available' => false, 'reason' => 'The existing Phase 5 ledger does not persist valuation layers sufficient for arbitrary historical as-of reconstruction.'], 'total_value' => number_format($rows->sum(fn (array $row): float => (float) $row['value']), 4, '.', ''), 'rows' => $rows->values()];
    }

    public function cogs(Request $request, Tenant $tenant): array
    {
        [$from, $to] = app(SalesAnalyticsService::class)->range($tenant, $request->input('from'), $request->input('to'));
        $stores = $this->scope->storeIds($request->user(), $tenant, $request->input('store_id'));
        $cost = (float) SaleItem::whereHas('sale', fn ($q) => $q->where('sales.tenant_id', $tenant->id)->whereIn('sales.store_id', $stores)->whereIn('sales.status', ['completed', 'partially_refunded', 'refunded'])->whereBetween('sales.completed_at', [$from, $to]))->selectRaw('COALESCE(SUM(sale_items.quantity * sale_items.unit_cost_at_sale),0) AS c')->value('c');
        $returns = (float) SaleItem::whereHas('returnItems.return', fn ($q) => $q->where('tenant_id', $tenant->id)->whereIn('store_id', $stores)->where('status', 'refunded')->whereBetween('refunded_at', [$from, $to]))->selectRaw('COALESCE(SUM(returned_quantity * unit_cost_at_sale),0) AS c')->value('c');

        return ['basis' => 'completed sale date for COGS; refunded return date for sellable return cost reversal', 'cogs' => number_format($cost, 4, '.', ''), 'sellable_return_cost_reversal' => number_format($returns, 4, '.', ''), 'return_adjusted_cogs' => number_format($cost - $returns, 4, '.', '')];
    }
}
