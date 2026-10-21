<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pos\CreateSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Payment;
use App\Models\PosRegister;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\Store;
use App\Services\PosAccessService;
use App\Services\PosSaleService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PosSaleController
{
    public function index(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $storeIds = $context->tenant()->stores()->get()->filter(fn (Store $store) => $access->canAccessStore($request->user(), $store))->pluck('id');
        $sales = Sale::query()->with(['store', 'register', 'cashier'])->where('sales.tenant_id', $context->tenant()->id)->whereIn('sales.store_id', $storeIds)->when($request->filled('status'), fn ($q) => $q->where('sales.status', $request->string('status')->toString()))->when($request->filled('payment_method'), fn ($q) => $q->whereHas('payments', fn ($payment) => $payment->where('payments.method', $request->string('payment_method')->toString())->where('payments.status', 'verified')))->when($request->filled('search'), fn ($q) => $q->where(fn ($inner) => $inner->where('sales.sale_number', 'ilike', '%'.$request->string('search').'%')->orWhereHas('cashier', fn ($cashier) => $cashier->where('users.name', 'ilike', '%'.$request->string('search').'%'))))->when($request->filled('from'), fn ($q) => $q->where('sales.created_at', '>=', $request->date('from')))->when($request->filled('to'), fn ($q) => $q->where('sales.created_at', '<=', $request->date('to')->endOfDay()))->latest('sales.created_at')->paginate(min((int) $request->integer('per_page', 50), 100));

        return response()->json(['success' => true, 'data' => SaleResource::collection($sales), 'meta' => ['total' => $sales->total(), 'current_page' => $sales->currentPage(), 'last_page' => $sales->lastPage()]]);
    }

    public function statistics(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $storeIds = $context->tenant()->stores()->get()->filter(fn (Store $store) => $access->canAccessStore($request->user(), $store))->pluck('id');
        $sales = Sale::query()->where('sales.tenant_id', $context->tenant()->id)->whereIn('sales.store_id', $storeIds)->when($request->filled('from'), fn ($q) => $q->where('sales.created_at', '>=', $request->date('from')))->when($request->filled('to'), fn ($q) => $q->where('sales.created_at', '<=', $request->date('to')->endOfDay()));
        $completed = (clone $sales)->whereIn('status', ['completed', 'partially_refunded', 'refunded']);
        $refunds = Refund::query()->where('refunds.tenant_id', $context->tenant()->id)->whereIn('refunds.store_id', $storeIds)->where('refunds.status', 'succeeded')->when($request->filled('from'), fn ($q) => $q->where('refunds.processed_at', '>=', $request->date('from')))->when($request->filled('to'), fn ($q) => $q->where('refunds.processed_at', '<=', $request->date('to')->endOfDay()));

        $verifiedPayments = Payment::query()->where('payments.tenant_id', $context->tenant()->id)->whereIn('payments.store_id', $storeIds)->where('payments.status', 'verified')->whereHas('sale', fn ($sale) => $sale->whereIn('status', ['completed', 'partially_refunded', 'refunded']))->when($request->filled('from'), fn ($q) => $q->where('payments.verified_at', '>=', $request->date('from')))->when($request->filled('to'), fn ($q) => $q->where('payments.verified_at', '<=', $request->date('to')->endOfDay()));

        return response()->json(['success' => true, 'data' => ['total_transactions' => (clone $sales)->count(), 'completed_transactions' => (clone $completed)->count(), 'gross_sales' => number_format((float) (clone $completed)->sum('subtotal'), 2, '.', ''), 'discounts' => number_format((float) (clone $completed)->sum('discount_total'), 2, '.', ''), 'tax_collected' => number_format((float) (clone $completed)->sum('tax_total'), 2, '.', ''), 'refund_amount' => number_format((float) $refunds->sum('amount'), 2, '.', ''), 'net_sales' => number_format((float) (clone $completed)->sum('grand_total') - (float) $refunds->sum('amount'), 2, '.', ''), 'cash_sales' => number_format((float) (clone $verifiedPayments)->where('method', 'cash')->sum('amount'), 2, '.', ''), 'card_sales' => number_format((float) (clone $verifiedPayments)->whereIn('method', ['card_terminal', 'qr', 'bank_transfer'])->sum('amount'), 2, '.', ''), 'voided_transactions' => (clone $sales)->where('status', 'cancelled')->count()]]);
    }

    public function store(CreateSaleRequest $request, TenantContext $context, PosSaleService $service): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw new UnprocessableEntityHttpException('Idempotency-Key is required when creating a sale.');
        }
        $store = Store::where('tenant_id', $context->tenant()->id)->where('uuid', $request->input('store_id'))->firstOrFail();
        $register = PosRegister::where('tenant_id', $context->tenant()->id)->where('uuid', $request->input('register_id'))->firstOrFail();
        Gate::forUser($request->user())->authorize('create', [Sale::class, $store]);
        $sale = $service->createDraft($store, $register, $request->validated(), $request->user(), $key);

        return response()->json(['success' => true, 'data' => new SaleResource($sale)], 201);
    }

    public function show(Sale $sale, TenantContext $context, Request $request): JsonResponse
    {
        $this->assertTenant($sale, $context);
        Gate::forUser($request->user())->authorize('view', $sale);

        return response()->json(['success' => true, 'data' => new SaleResource($sale->load(['items.variant', 'payments', 'store', 'register', 'cashier', 'receipt', 'returns.refund']))]);
    }

    public function hold(Sale $sale, TenantContext $context, Request $request, PosSaleService $service): JsonResponse
    {
        $this->assertTenant($sale, $context);
        Gate::forUser($request->user())->authorize('manage', $sale);

        return response()->json(['success' => true, 'data' => new SaleResource($service->hold($sale, $request->user()))]);
    }

    public function resume(Sale $sale, TenantContext $context, Request $request, PosSaleService $service): JsonResponse
    {
        $this->assertTenant($sale, $context);
        Gate::forUser($request->user())->authorize('manage', $sale);

        return response()->json(['success' => true, 'data' => new SaleResource($service->resume($sale, $request->user()))]);
    }

    public function cancel(Sale $sale, TenantContext $context, Request $request, PosSaleService $service): JsonResponse
    {
        $this->assertTenant($sale, $context);
        Gate::forUser($request->user())->authorize('manage', $sale);

        return response()->json(['success' => true, 'data' => new SaleResource($service->cancel($sale, $request->user()))]);
    }

    public function complete(Sale $sale, TenantContext $context, Request $request, PosSaleService $service): JsonResponse
    {
        $this->assertTenant($sale, $context);
        Gate::forUser($request->user())->authorize('manage', $sale);

        return response()->json(['success' => true, 'data' => new SaleResource($service->complete($sale, $request->user()))]);
    }

    private function assertTenant(Sale $sale, TenantContext $context): void
    {
        if ((int) $sale->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested sale was not found.');
        }
    }
}
