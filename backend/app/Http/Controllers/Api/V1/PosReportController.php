<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Store;
use App\Services\PosAccessService;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosReportController
{
    public function daily(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $query = $this->sales($request, $context, $access)->whereIn('sales.status', ['completed', 'partially_refunded', 'refunded']);
        $row = (clone $query)->selectRaw('COUNT(sales.id) AS transaction_count, COALESCE(SUM(sales.subtotal), 0) AS subtotal, COALESCE(SUM(sales.discount_total), 0) AS discounts, COALESCE(SUM(sales.tax_total), 0) AS taxes, COALESCE(SUM(sales.grand_total), 0) AS gross_sales, COALESCE(SUM(sales.refunded_total), 0) AS refunds')->first();

        return response()->json(['success' => true, 'data' => ['transaction_count' => (int) $row->transaction_count, 'subtotal' => $row->subtotal, 'discounts' => $row->discounts, 'taxes' => $row->taxes, 'gross_sales' => $row->gross_sales, 'refunds' => $row->refunds, 'net_sales' => bcsub((string) $row->gross_sales, (string) $row->refunds, 2)]]);
    }

    public function payments(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $storeIds = $this->storeIds($request, $context, $access);
        $rows = Payment::where('payments.tenant_id', $context->tenant()->id)->whereIn('payments.store_id', $storeIds)->where('payments.status', 'verified')
            ->when($request->filled('from'), fn ($q) => $q->where('payments.created_at', '>=', Carbon::parse($request->input('from'))))
            ->when($request->filled('to'), fn ($q) => $q->where('payments.created_at', '<=', Carbon::parse($request->input('to'))->endOfDay()))
            ->groupBy('payments.method')->selectRaw('payments.method, COUNT(payments.id) AS payment_count, SUM(payments.amount) AS amount')->orderBy('payments.method')->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function cashiers(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $rows = $this->sales($request, $context, $access)->whereIn('sales.status', ['completed', 'partially_refunded', 'refunded'])->join('users', 'users.id', '=', 'sales.cashier_user_id')->groupBy('sales.cashier_user_id', 'users.name')->selectRaw('sales.cashier_user_id, users.name, COUNT(sales.id) AS transaction_count, SUM(sales.grand_total) AS gross_sales')->orderByDesc('gross_sales')->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function returns(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $storeIds = $this->storeIds($request, $context, $access);
        $rows = SaleReturn::where('sale_returns.tenant_id', $context->tenant()->id)->whereIn('sale_returns.store_id', $storeIds)->when($request->filled('from'), fn ($q) => $q->where('sale_returns.created_at', '>=', Carbon::parse($request->input('from'))))->when($request->filled('to'), fn ($q) => $q->where('sale_returns.created_at', '<=', Carbon::parse($request->input('to'))->endOfDay()))->groupBy('sale_returns.status')->selectRaw('sale_returns.status, COUNT(sale_returns.id) AS return_count, SUM(sale_returns.refund_total) AS refund_total')->orderBy('sale_returns.status')->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    private function sales(Request $request, TenantContext $context, PosAccessService $access)
    {
        return Sale::where('sales.tenant_id', $context->tenant()->id)->whereIn('sales.store_id', $this->storeIds($request, $context, $access))->when($request->filled('from'), fn ($q) => $q->where('sales.created_at', '>=', Carbon::parse($request->input('from'))))->when($request->filled('to'), fn ($q) => $q->where('sales.created_at', '<=', Carbon::parse($request->input('to'))->endOfDay()));
    }

    private function storeIds(Request $request, TenantContext $context, PosAccessService $access)
    {
        return $context->tenant()->stores()->get()->filter(fn (Store $store) => $access->canAccessStore($request->user(), $store))->pluck('id');
    }
}
