<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pos\CreateReturnRequest;
use App\Http\Resources\SaleReturnResource;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Store;
use App\Models\Refund;
use App\Services\PosAccessService;
use App\Services\SaleReturnService;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PosReturnController
{
    public function index(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $storeIds = $context->tenant()->stores()->get()->filter(fn (Store $store) => $access->canAccessStore($request->user(), $store))->pluck('id');
        $returns = SaleReturn::query()->with(['sale', 'store', 'requester', 'approver', 'refund', 'items.saleItem', 'items.variant'])
            ->where('sale_returns.tenant_id', $context->tenant()->id)
            ->whereIn('sale_returns.store_id', $storeIds)
            ->when($request->filled('status'), fn ($q) => $q->where('sale_returns.status', $request->string('status')->toString()))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($inner) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $inner->where('sale_returns.return_number', 'ilike', $term)
                    ->orWhere('sale_returns.reason', 'ilike', $term)
                    ->orWhereHas('sale', fn ($sale) => $sale->where('sales.sale_number', 'ilike', $term));
            }))
            ->when($request->filled('from'), fn ($q) => $q->where('sale_returns.created_at', '>=', Carbon::parse($request->input('from'))))
            ->when($request->filled('to'), fn ($q) => $q->where('sale_returns.created_at', '<=', Carbon::parse($request->input('to'))->endOfDay()))
            ->latest('sale_returns.created_at')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return response()->json(['success' => true, 'data' => SaleReturnResource::collection($returns), 'meta' => ['total' => $returns->total(), 'current_page' => $returns->currentPage(), 'last_page' => $returns->lastPage()]]);
    }

    public function statistics(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $tenantId = (int) $context->tenant()->id;
        $storeIds = $context->tenant()->stores()->get()->filter(fn (Store $store) => $access->canAccessStore($request->user(), $store))->pluck('id');
        $returns = SaleReturn::query()->where('sale_returns.tenant_id', $tenantId)->whereIn('sale_returns.store_id', $storeIds)
            ->when($request->filled('from'), fn ($q) => $q->where('sale_returns.created_at', '>=', Carbon::parse($request->input('from'))))
            ->when($request->filled('to'), fn ($q) => $q->where('sale_returns.created_at', '<=', Carbon::parse($request->input('to'))->endOfDay()));
        $refunds = Refund::query()->where('refunds.tenant_id', $tenantId)->whereIn('refunds.store_id', $storeIds)
            ->when($request->filled('from'), fn ($q) => $q->where('refunds.processed_at', '>=', Carbon::parse($request->input('from'))))
            ->when($request->filled('to'), fn ($q) => $q->where('refunds.processed_at', '<=', Carbon::parse($request->input('to'))->endOfDay()));
        $sales = Sale::query()->where('sales.tenant_id', $tenantId)->whereIn('sales.store_id', $storeIds)
            ->whereIn('sales.status', ['completed', 'partially_refunded', 'refunded'])
            ->when($request->filled('from'), fn ($q) => $q->where('sales.created_at', '>=', Carbon::parse($request->input('from'))))
            ->when($request->filled('to'), fn ($q) => $q->where('sales.created_at', '<=', Carbon::parse($request->input('to'))->endOfDay()));
        $successfulRefunds = (clone $refunds)->where('refunds.status', 'succeeded');
        $pendingRefunds = (clone $refunds)->whereIn('refunds.status', ['pending', 'processing']);
        $pendingReturnAmount = (clone $returns)->where('sale_returns.status', 'approved')->whereDoesntHave('refund')->sum('sale_returns.refund_total');
        $methodTotals = (clone $successfulRefunds)->selectRaw('refunds.method, COALESCE(SUM(refunds.amount), 0) AS amount')->groupBy('refunds.method')->orderBy('refunds.method')->get()->mapWithKeys(fn ($row) => [$row->method => number_format((float) $row->amount, 2, '.', '')]);
        $completedSales = (clone $sales)->count();
        $returnCount = (clone $returns)->count();

        return response()->json(['success' => true, 'data' => [
            'total_returns' => $returnCount,
            'pending_returns' => (clone $returns)->where('status', 'requested')->count(),
            'approved_returns' => (clone $returns)->where('status', 'approved')->count(),
            'completed_returns' => (clone $returns)->where('status', 'refunded')->count(),
            'rejected_returns' => (clone $returns)->whereIn('status', ['rejected', 'cancelled'])->count(),
            'actual_refunds' => number_format((float) $successfulRefunds->sum('amount'), 2, '.', ''),
            'pending_refunds' => number_format((float) $pendingRefunds->sum('amount') + (float) $pendingReturnAmount, 2, '.', ''),
            'return_rate' => $completedSales > 0 ? number_format(($returnCount / $completedSales) * 100, 2, '.', '') : '0.00',
            'refunds_by_payment_method' => $methodTotals,
        ]]);
    }

    public function show(SaleReturn $return, TenantContext $context, Request $request): JsonResponse
    {
        $this->assertTenant($return, $context);
        Gate::forUser($request->user())->authorize('view', $return);

        return response()->json(['success' => true, 'data' => new SaleReturnResource($return->load(['items.saleItem', 'items.variant', 'sale', 'store', 'requester', 'approver', 'refund']))]);
    }

    public function store(CreateReturnRequest $request, Sale $sale, TenantContext $context, SaleReturnService $service): JsonResponse
    {
        $this->assertSaleTenant($sale, $context);
        Gate::forUser($request->user())->authorize('create', [SaleReturn::class, $sale]);
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw new UnprocessableEntityHttpException('Idempotency-Key is required when creating a return.');
        }
        $return = $service->create($sale, $request->validated(), $request->user(), $key);

        return response()->json(['success' => true, 'data' => new SaleReturnResource($return)], 201);
    }

    public function approve(SaleReturn $return, TenantContext $context, Request $request, SaleReturnService $service): JsonResponse
    {
        $this->assertTenant($return, $context);
        Gate::forUser($request->user())->authorize('approve', $return);

        return response()->json(['success' => true, 'data' => new SaleReturnResource($service->approve($return, $request->user()))]);
    }

    private function assertTenant(SaleReturn $return, TenantContext $context): void
    {
        if ((int) $return->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested return was not found.');
        }
    }

    private function assertSaleTenant(Sale $sale, TenantContext $context): void
    {
        if ((int) $sale->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested sale was not found.');
        }
    }
}
