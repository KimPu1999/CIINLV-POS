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
        $query = $this->sales($request, $context, $access)->where('status', 'completed');
        $row = (clone $query)->selectRaw('COUNT(*) AS transaction_count, COALESCE(SUM(subtotal), 0) AS subtotal, COALESCE(SUM(discount_total), 0) AS discounts, COALESCE(SUM(tax_total), 0) AS taxes, COALESCE(SUM(grand_total), 0) AS gross_sales, COALESCE(SUM(refunded_total), 0) AS refunds')->first();

        return response()->json(['success' => true, 'data' => ['transaction_count' => (int) $row->transaction_count, 'subtotal' => $row->subtotal, 'discounts' => $row->discounts, 'taxes' => $row->taxes, 'gross_sales' => $row->gross_sales, 'refunds' => $row->refunds, 'net_sales' => bcsub((string) $row->gross_sales, (string) $row->refunds, 2)]]);
    }

    public function payments(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $storeIds = $this->storeIds($request, $context, $access);
        $rows = Payment::where('tenant_id', $context->tenant()->id)->whereIn('store_id', $storeIds)->where('status', 'verified')
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->input('from'))))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->input('to'))->endOfDay()))
            ->groupBy('method')->selectRaw('method, COUNT(*) AS payment_count, SUM(amount) AS amount')->orderBy('method')->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function cashiers(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $rows = $this->sales($request, $context, $access)->where('status', 'completed')->join('users', 'users.id', '=', 'sales.cashier_user_id')->groupBy('cashier_user_id', 'users.name')->selectRaw('cashier_user_id, users.name, COUNT(sales.id) AS transaction_count, SUM(grand_total) AS gross_sales')->orderByDesc('gross_sales')->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function returns(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $storeIds = $this->storeIds($request, $context, $access);
        $rows = SaleReturn::where('tenant_id', $context->tenant()->id)->whereIn('store_id', $storeIds)->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->input('from'))))->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->input('to'))->endOfDay()))->groupBy('status')->selectRaw('status, COUNT(*) AS return_count, SUM(refund_total) AS refund_total')->orderBy('status')->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    private function sales(Request $request, TenantContext $context, PosAccessService $access)
    {
        return Sale::where('tenant_id', $context->tenant()->id)->whereIn('store_id', $this->storeIds($request, $context, $access))->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->input('from'))))->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->input('to'))->endOfDay()));
    }

    private function storeIds(Request $request, TenantContext $context, PosAccessService $access)
    {
        return $context->tenant()->stores()->get()->filter(fn (Store $store) => $access->canAccessStore($request->user(), $store))->pluck('id');
    }
}
