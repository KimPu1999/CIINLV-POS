<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SalesAnalyticsService
{
    public function __construct(private readonly ReportScopeService $scope) {}

    public function timezone(Tenant $tenant): string
    {
        return $tenant->businessSetting?->timezone ?: config('app.timezone', 'UTC');
    }

    public function range(Tenant $tenant, ?string $from = null, ?string $to = null): array
    {
        $tz = $this->timezone($tenant);
        $start = $from ? CarbonImmutable::parse($from, $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfDay();
        $end = $to ? CarbonImmutable::parse($to, $tz)->endOfDay() : ($from ? $start->endOfDay() : CarbonImmutable::now($tz)->endOfDay());

        return [$start->utc(), $end->utc(), $tz];
    }

    public function summary(Request $request, Tenant $tenant): array
    {
        [$from, $to, $timezone] = $this->range($tenant, $request->input('from'), $request->input('to'));
        $stores = $this->scope->storeIds($request->user(), $tenant, $request->input('store_id'));
        $sales = $this->salesQuery($tenant, $stores, $from, $to);
        $row = (clone $sales)->selectRaw('COUNT(*) AS transaction_count, COALESCE(SUM(subtotal),0) AS gross_merchandise_sales, COALESCE(SUM(discount_total),0) AS discounts, COALESCE(SUM(tax_total),0) AS taxes, COALESCE(SUM(grand_total),0) AS gross_sales')->first();
        $refunds = (float) Refund::where('tenant_id', $tenant->id)->whereIn('store_id', $stores)->where('status', 'succeeded')->whereBetween('processed_at', [$from, $to])->sum('amount');
        $netBeforeTax = (float) $row->gross_merchandise_sales - (float) $row->discounts;
        $netRevenue = $netBeforeTax - $refunds;
        $collected = (float) Payment::where('tenant_id', $tenant->id)->whereIn('store_id', $stores)->where('status', 'verified')->whereBetween('verified_at', [$from, $to])->sum('amount');
        $cogs = (float) SaleItem::whereHas('sale', fn ($q) => $q->where('tenant_id', $tenant->id)->whereIn('store_id', $stores)->where('status', 'completed')->whereBetween('completed_at', [$from, $to]))->selectRaw('COALESCE(SUM(quantity * unit_cost_at_sale),0) AS c')->value('c');
        $profit = $netBeforeTax - $cogs;

        return [
            'basis' => ['sales' => 'completed_at', 'payments' => 'verified_at', 'refunds' => 'processed_at', 'timezone' => $timezone],
            'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
            'currency' => $tenant->businessSetting?->currency_code ?: 'MMK',
            'transaction_count' => (int) $row->transaction_count,
            'gross_merchandise_sales' => $this->money($row->gross_merchandise_sales),
            'discounts' => $this->money($row->discounts),
            'net_sales_before_tax' => $this->money($netBeforeTax),
            'tax_collected_on_completed_sales' => $this->money($row->taxes),
            'gross_sales' => $this->money($row->gross_sales),
            'collected_payments' => $this->money($collected),
            'refunds' => $this->money($refunds),
            'net_revenue' => $this->money($netRevenue),
            'cogs' => $this->money($cogs, 4),
            'gross_profit' => $this->money($profit, 4),
            'gross_profit_margin' => $netBeforeTax > 0 ? round(($profit / $netBeforeTax) * 100, 4) : null,
            'average_order_value' => (int) $row->transaction_count > 0 ? $this->money((float) $row->gross_sales / (int) $row->transaction_count) : '0.00',
        ];
    }

    public function byPaymentMethod(Request $request, Tenant $tenant): array
    {
        [$from, $to] = $this->range($tenant, $request->input('from'), $request->input('to'));
        $rows = Payment::where('tenant_id', $tenant->id)->whereIn('store_id', $this->scope->storeIds($request->user(), $tenant, $request->input('store_id')))->where('status', 'verified')->whereBetween('verified_at', [$from, $to])->groupBy('method')->orderBy('method')->selectRaw('method, COUNT(*) AS payment_count, SUM(amount) AS amount')->get();

        return ['basis' => 'verified_at', 'rows' => $rows];
    }

    public function byCashier(Request $request, Tenant $tenant): array
    {
        [$from, $to] = $this->range($tenant, $request->input('from'), $request->input('to'));
        $rows = $this->salesQuery($tenant, $this->scope->storeIds($request->user(), $tenant, $request->input('store_id')), $from, $to)->join('users', 'users.id', '=', 'sales.cashier_user_id')->groupBy('sales.cashier_user_id', 'users.name')->selectRaw('sales.cashier_user_id, users.name, COUNT(sales.id) AS transaction_count, SUM(sales.grand_total) AS gross_sales')->orderByDesc('gross_sales')->get();

        return ['basis' => 'completed_at', 'rows' => $rows];
    }

    public function topProducts(Request $request, Tenant $tenant): array
    {
        [$from, $to] = $this->range($tenant, $request->input('from'), $request->input('to'));
        $rows = SaleItem::with('variant.product')->whereHas('sale', fn ($q) => $q->where('tenant_id', $tenant->id)->whereIn('store_id', $this->scope->storeIds($request->user(), $tenant, $request->input('store_id')))->where('status', 'completed')->whereBetween('completed_at', [$from, $to]))->selectRaw('product_variant_id, SUM(quantity) AS quantity_sold, SUM(line_total) AS sales_total')->groupBy('product_variant_id')->orderByDesc('quantity_sold')->limit(20)->get();

        return ['basis' => 'completed_at', 'rows' => $rows];
    }

    public function salesQuery(Tenant $tenant, $stores, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Sale::where('tenant_id', $tenant->id)->whereIn('store_id', $stores)->where('status', 'completed')->whereBetween('completed_at', [$from, $to]);
    }

    private function money(float|string|int|null $value, int $scale = 2): string
    {
        return number_format((float) $value, $scale, '.', '');
    }
}
