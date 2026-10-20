<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturnItem;
use App\Models\Tenant;
use App\Services\TranslationService;
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
        $row = (clone $sales)->selectRaw('COUNT(sales.id) AS transaction_count, COALESCE(SUM(sales.subtotal),0) AS gross_merchandise_sales, COALESCE(SUM(sales.discount_total),0) AS discounts, COALESCE(SUM(sales.tax_total),0) AS taxes, COALESCE(SUM(sales.grand_total),0) AS gross_sales')->first();
        $refunds = (float) Refund::where('refunds.tenant_id', $tenant->id)->whereIn('refunds.store_id', $stores)->where('refunds.status', 'succeeded')->whereBetween('refunds.processed_at', [$from, $to])->sum('refunds.amount');
        $netBeforeTax = (float) $row->gross_merchandise_sales - (float) $row->discounts;
        $netRevenue = $netBeforeTax - $refunds;
        $collected = (float) Payment::where('payments.tenant_id', $tenant->id)->whereIn('payments.store_id', $stores)->where('payments.status', 'verified')->whereBetween('payments.verified_at', [$from, $to])->sum('payments.amount');
        $cogs = (float) SaleItem::whereHas('sale', fn ($q) => $q->where('sales.tenant_id', $tenant->id)->whereIn('sales.store_id', $stores)->whereIn('sales.status', ['completed', 'partially_refunded', 'refunded'])->whereBetween('sales.completed_at', [$from, $to]))->selectRaw('COALESCE(SUM(sale_items.quantity * sale_items.unit_cost_at_sale),0) AS c')->value('c');
        $returnedCogs = (float) SaleReturnItem::query()->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')->join('sale_items', 'sale_items.id', '=', 'sale_return_items.sale_item_id')->where('sale_return_items.tenant_id', $tenant->id)->where('sale_items.tenant_id', $tenant->id)->whereIn('sale_returns.store_id', $stores)->where('sale_returns.status', 'refunded')->whereBetween('sale_returns.refunded_at', [$from, $to])->selectRaw('COALESCE(SUM(sale_return_items.quantity * sale_items.unit_cost_at_sale),0) AS c')->value('c');
        $cogs = max(0, $cogs - $returnedCogs);
        $profit = $netRevenue - $cogs;

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
            'gross_profit_margin' => $netRevenue > 0 ? round(($profit / $netRevenue) * 100, 4) : null,
            'average_order_value' => (int) $row->transaction_count > 0 ? $this->money((float) $row->gross_sales / (int) $row->transaction_count) : '0.00',
        ];
    }

    public function byPaymentMethod(Request $request, Tenant $tenant): array
    {
        [$from, $to] = $this->range($tenant, $request->input('from'), $request->input('to'));
        $rows = Payment::where('payments.tenant_id', $tenant->id)->whereIn('payments.store_id', $this->scope->storeIds($request->user(), $tenant, $request->input('store_id')))->where('payments.status', 'verified')->whereBetween('payments.verified_at', [$from, $to])->groupBy('payments.method')->orderBy('payments.method')->selectRaw('payments.method, COUNT(payments.id) AS payment_count, SUM(payments.amount) AS amount')->get();

        return ['basis' => 'verified_at', 'rows' => $rows];
    }

    public function byCashier(Request $request, Tenant $tenant): array
    {
        [$from, $to] = $this->range($tenant, $request->input('from'), $request->input('to'));
        $rows = $this->salesQuery($tenant, $this->scope->storeIds($request->user(), $tenant, $request->input('store_id')), $from, $to)->join('users', 'users.id', '=', 'sales.cashier_user_id')->groupBy('sales.cashier_user_id', 'users.name')->selectRaw('sales.cashier_user_id, users.name, COUNT(sales.id) AS transaction_count, SUM(sales.grand_total) AS gross_sales')->orderByDesc('gross_sales')->get();

        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);
        return ['basis' => 'completed_at', 'rows' => $rows->map(fn ($row): array => [
            'product_variant_id' => $row->product_variant_id,
            'quantity_sold' => $row->quantity_sold,
            'sales_total' => $row->sales_total,
            'variant' => $row->variant ? ['sku' => $row->variant->sku, 'product' => $row->variant->product ? ['name' => $translations->localizedValue($row->variant->product, 'name', $locale)] : null] : null,
        ])->values()];
    }

    public function topProducts(Request $request, Tenant $tenant): array
    {
        [$from, $to] = $this->range($tenant, $request->input('from'), $request->input('to'));
        $rows = SaleItem::with('variant.product')->whereHas('sale', fn ($q) => $q->where('sales.tenant_id', $tenant->id)->whereIn('sales.store_id', $this->scope->storeIds($request->user(), $tenant, $request->input('store_id')))->whereIn('sales.status', ['completed', 'partially_refunded', 'refunded'])->whereBetween('sales.completed_at', [$from, $to]))->selectRaw('sale_items.product_variant_id, SUM(sale_items.quantity) AS quantity_sold, SUM(sale_items.line_total) AS sales_total')->groupBy('sale_items.product_variant_id')->orderByDesc('quantity_sold')->limit(20)->get();

        return ['basis' => 'completed_at', 'rows' => $rows];
    }

    public function trend(Request $request, Tenant $tenant): array
    {
        $period = in_array($request->input('period'), ['daily', 'weekly', 'monthly'], true) ? $request->input('period') : 'daily';
        $timezone = $this->timezone($tenant);
        if ($request->filled('from') || $request->filled('to')) {
            [$fromUtc, $toUtc] = $this->range($tenant, $request->input('from'), $request->input('to'));
            $from = $fromUtc->setTimezone($timezone);
            $to = $toUtc->setTimezone($timezone);
        } else {
            $now = CarbonImmutable::now($timezone);
            [$from, $to] = match ($period) {
                'weekly' => [$now->subWeeks(7)->startOfWeek(), $now->endOfWeek()],
                'monthly' => [$now->subMonths(5)->startOfMonth(), $now->endOfMonth()],
                default => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            };
        }
        $stores = $this->scope->storeIds($request->user(), $tenant, $request->input('store_id'));
        $rows = $this->salesQuery($tenant, $stores, $from->utc(), $to->utc())
            ->selectRaw("date_trunc(?, sales.completed_at AT TIME ZONE ?)::date AS bucket, COUNT(sales.id) AS transaction_count, COALESCE(SUM(sales.grand_total), 0) AS gross_sales", [$period === 'daily' ? 'day' : ($period === 'weekly' ? 'week' : 'month'), $timezone])
            ->groupBy('bucket')->orderBy('bucket')->get()->keyBy(fn ($row) => (string) $row->bucket);
        $refunds = Refund::where('refunds.tenant_id', $tenant->id)->whereIn('refunds.store_id', $stores)->where('refunds.status', 'succeeded')->whereBetween('refunds.processed_at', [$from->utc(), $to->utc()])
            ->selectRaw("date_trunc(?, refunds.processed_at AT TIME ZONE ?)::date AS bucket, COALESCE(SUM(refunds.amount), 0) AS refunds", [$period === 'daily' ? 'day' : ($period === 'weekly' ? 'week' : 'month'), $timezone])->groupBy('bucket')->pluck('refunds', 'bucket');
        $cursor = $period === 'daily' ? $from : ($period === 'weekly' ? $from->startOfWeek() : $from->startOfMonth());
        $end = $period === 'daily' ? $to->startOfDay() : ($period === 'weekly' ? $to->startOfWeek() : $to->startOfMonth());
        $points = [];
        while ($cursor <= $end) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);
            $gross = (float) ($row->gross_sales ?? 0);
            $refund = (float) ($refunds[$key] ?? 0);
            $points[] = ['label' => $period === 'monthly' ? $cursor->format('M Y') : $cursor->format('d M'), 'date' => $key, 'net_sales' => number_format($gross - $refund, 2, '.', ''), 'transactions' => (int) ($row->transaction_count ?? 0)];
            $cursor = $period === 'daily' ? $cursor->addDay() : ($period === 'weekly' ? $cursor->addWeek() : $cursor->addMonth());
        }
        return ['period' => $period, 'currency' => $tenant->businessSetting?->currency_code ?: 'MMK', 'points' => $points, 'basis' => ['sales' => 'completed_at', 'refunds' => 'processed_at', 'timezone' => $timezone]];
    }

    public function salesQuery(Tenant $tenant, $stores, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Sale::where('sales.tenant_id', $tenant->id)->whereIn('sales.store_id', $stores)->whereIn('sales.status', ['completed', 'partially_refunded', 'refunded'])->whereBetween('sales.completed_at', [$from, $to]);
    }

    private function money(float|string|int|null $value, int $scale = 2): string
    {
        return number_format((float) $value, $scale, '.', '');
    }
}
