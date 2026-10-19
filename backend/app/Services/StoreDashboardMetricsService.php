<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\Supplier;
use Carbon\CarbonImmutable;

class StoreDashboardMetricsService
{
    public function for(Store $store): array
    {
        $timezone = $store->timezone ?: config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);
        $todayFrom = $now->startOfDay()->utc();
        $todayTo = $now->endOfDay()->utc();
        $weekFrom = $now->startOfWeek()->utc();
        $monthFrom = $now->startOfMonth()->utc();

        $sales = Sale::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->where('status', 'completed');

        $salesTotal = fn (CarbonImmutable $from, CarbonImmutable $to): string => number_format(
            (float) (clone $sales)->whereBetween('completed_at', [$from, $to])->sum('grand_total'),
            2,
            '.',
            ''
        );

        $locationIds = InventoryLocation::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->where('location_type', 'store')
            ->where('status', 'active')
            ->pluck('id');

        $balances = InventoryBalance::query()
            ->where('tenant_id', $store->tenant_id)
            ->whereIn('inventory_location_id', $locationIds);
        $lowStock = (clone $balances)->whereRaw('(on_hand_quantity - reserved_quantity) <= (SELECT reorder_level FROM product_variants WHERE product_variants.id = inventory_balances.product_variant_id)');
        $outOfStock = (clone $balances)->whereRaw('(on_hand_quantity - reserved_quantity) <= 0');

        $recentSales = (clone $sales)
            ->with('cashier:id,name')
            ->latest('completed_at')
            ->limit(10)
            ->get(['id', 'uuid', 'sale_number', 'cashier_user_id', 'grand_total', 'currency_code', 'completed_at'])
            ->map(fn (Sale $sale): array => [
                'id' => $sale->uuid,
                'sale_number' => $sale->sale_number,
                'cashier' => $sale->cashier?->name,
                'total' => $sale->grand_total,
                'currency_code' => $sale->currency_code,
                'completed_at' => $sale->completed_at?->toIso8601String(),
            ])->values()->all();

        $payments = Payment::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->where('status', 'verified')
            ->whereBetween('verified_at', [$todayFrom, $todayTo])
            ->groupBy('method')
            ->orderBy('method')
            ->selectRaw('method, SUM(amount) AS amount')
            ->get()
            ->mapWithKeys(fn (object $payment): array => [$payment->method => number_format((float) $payment->amount, 2, '.', '')])
            ->all();

        $refundsToday = Refund::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->where('status', 'succeeded')
            ->whereBetween('processed_at', [$todayFrom, $todayTo])
            ->sum('amount');

        return [
            'currency_code' => $store->currency_code ?: config('pos.default_currency', 'MMK'),
            'as_of' => $now->toIso8601String(),
            'sales_overview' => [
                'today' => $salesTotal($todayFrom, $todayTo),
                'transaction_count_today' => (clone $sales)->whereBetween('completed_at', [$todayFrom, $todayTo])->count(),
                'refunds_today' => number_format((float) $refundsToday, 2, '.', ''),
            ],
            'daily_revenue' => $salesTotal($todayFrom, $todayTo),
            'weekly_revenue' => $salesTotal($weekFrom, $todayTo),
            'monthly_revenue' => $salesTotal($monthFrom, $todayTo),
            'total_products' => Product::where('tenant_id', $store->tenant_id)->where('status', 'active')->count(),
            'stock_summary' => [
                'on_hand_quantity' => (string) (clone $balances)->sum('on_hand_quantity'),
                'available_quantity' => (string) (clone $balances)->selectRaw('COALESCE(SUM(on_hand_quantity - reserved_quantity), 0) AS quantity')->value('quantity'),
                'inventory_value' => number_format((float) (clone $balances)->selectRaw('COALESCE(SUM(on_hand_quantity * average_cost), 0) AS value')->value('value'), 4, '.', ''),
            ],
            'low_stock' => ['count' => $lowStock->count()],
            'out_of_stock' => ['count' => $outOfStock->count()],
            'recent_transactions' => $recentSales,
            'payment_summary' => $payments,
            'staff_overview' => StoreMembership::where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('status', 'active')->whereNull('revoked_at')->selectRaw('role, COUNT(*) AS count')->groupBy('role')->orderBy('role')->pluck('count', 'role')->all(),
            'supplier_overview' => ['active_count' => Supplier::where('tenant_id', $store->tenant_id)->where('status', 'active')->count()],
        ];
    }
}
