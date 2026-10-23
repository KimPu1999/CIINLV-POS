<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pos\CreateSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Payment;
use App\Models\PosRegister;
use App\Models\Sale;
use App\Models\Store;
use App\Services\PosAccessService;
use App\Services\PosSaleService;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PosSaleController
{
    public function index(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $validated = $request->validate($this->filterRules(true));
        $storeIds = $this->authorizedStoreIds($request, $context, $access, $validated['store_id'] ?? null);
        $sales = $this->salesQuery($request, $context, $storeIds, $validated)
            ->with(['store', 'register', 'cashier', 'receipt'])
            ->withCount('items')
            ->orderBy('sales.created_at', ($validated['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')
            ->orderBy('sales.id', 'desc')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json(['success' => true, 'data' => SaleResource::collection($sales), 'meta' => ['total' => $sales->total(), 'current_page' => $sales->currentPage(), 'last_page' => $sales->lastPage(), 'per_page' => $sales->perPage(), 'from' => $sales->firstItem(), 'to' => $sales->lastItem()]]);
    }

    public function statistics(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $validated = $request->validate($this->filterRules(false));
        $storeIds = $this->authorizedStoreIds($request, $context, $access, $validated['store_id'] ?? null);
        $sales = $this->salesQuery($request, $context, $storeIds, $validated);
        $completed = (clone $sales)->whereIn('status', ['completed', 'partially_refunded', 'refunded']);
        $verifiedStatuses = ['paid', 'partially_refunded', 'refunded'];
        $pendingStatuses = ['unpaid', 'pending', 'partially_paid'];
        $refunds = (clone $completed)->sum('refunded_total');
        $verifiedRevenue = (clone $completed)->whereIn('payment_status', $verifiedStatuses)->sum('paid_total');

        $verifiedPayments = Payment::query()->where('payments.tenant_id', $context->tenant()->id)->whereIn('payments.store_id', $storeIds)->where('payments.status', 'verified')->whereHas('sale', fn ($sale) => $sale->whereIn('status', ['completed', 'partially_refunded', 'refunded']))->when($request->filled('from'), fn ($q) => $q->where('payments.verified_at', '>=', Carbon::parse($request->string('from')->toString())->startOfDay()))->when($request->filled('to'), fn ($q) => $q->where('payments.verified_at', '<=', Carbon::parse($request->string('to')->toString())->endOfDay()));

        return response()->json(['success' => true, 'data' => ['total_transactions' => (clone $sales)->count(), 'completed_transactions' => (clone $completed)->count(), 'gross_sales' => number_format((float) (clone $completed)->sum('subtotal'), 2, '.', ''), 'discounts' => number_format((float) (clone $completed)->sum('discount_total'), 2, '.', ''), 'tax_collected' => number_format((float) (clone $completed)->sum('tax_total'), 2, '.', ''), 'refund_amount' => number_format((float) $refunds, 2, '.', ''), 'net_sales' => number_format((float) $verifiedRevenue - (float) $refunds, 2, '.', ''), 'cash_sales' => number_format((float) (clone $verifiedPayments)->where('method', 'cash')->sum('amount'), 2, '.', ''), 'card_sales' => number_format((float) (clone $verifiedPayments)->whereIn('method', ['card_terminal', 'qr', 'bank_transfer'])->sum('amount'), 2, '.', ''), 'pending_payments' => number_format((float) (clone $sales)->whereIn('payment_status', $pendingStatuses)->sum('balance_due'), 2, '.', ''), 'verified_revenue' => number_format((float) $verifiedRevenue, 2, '.', ''), 'voided_transactions' => (clone $sales)->where('status', 'cancelled')->count()]]);
    }

    private function filterRules(bool $pagination): array
    {
        $rules = [
            'store_id' => ['nullable', 'uuid'],
            'cashier_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:40'],
            'payment_status' => ['nullable', 'string', 'max:40'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'payment_verification_status' => ['nullable', Rule::in(['pending', 'verified', 'failed', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
        ];
        if ($pagination) {
            $rules['per_page'] = ['nullable', 'integer', Rule::in([10, 25, 50, 100])];
            $rules['page'] = ['nullable', 'integer', 'min:1'];
        }

        return $rules;
    }

    private function authorizedStoreIds(Request $request, TenantContext $context, PosAccessService $access, ?string $requestedStore)
    {
        $stores = $context->tenant()->stores()->get()->filter(fn (Store $store) => $access->canAccessStore($request->user(), $store));

        if ($requestedStore !== null) {
            if (! $stores->contains('uuid', $requestedStore)) {
                throw new NotFoundHttpException('The selected branch was not found.');
            }
            $stores = $stores->where('uuid', $requestedStore);
        }

        return $stores->pluck('id')->values();
    }

    private function salesQuery(Request $request, TenantContext $context, $storeIds, array $filters)
    {
        $timezone = $context->tenant()->businessSetting?->timezone ?: 'UTC';
        $query = Sale::query()->where('sales.tenant_id', $context->tenant()->id)->whereIn('sales.store_id', $storeIds)
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('sales.status', $value))
            ->when($filters['payment_status'] ?? null, fn ($q, $value) => $q->where('sales.payment_status', $value))
            ->when($filters['cashier_id'] ?? null, fn ($q, $value) => $q->where('sales.cashier_user_id', $value))
            ->when($filters['payment_method'] ?? null, fn ($q, $value) => $q->whereHas('payments', fn ($payment) => $payment->where('payments.method', $value)->where('payments.status', 'verified')))
            ->when($filters['payment_verification_status'] ?? null, fn ($q, $value) => $q->whereHas('payments', fn ($payment) => $payment->where('payments.status', $value)))
            ->when($filters['search'] ?? null, fn ($q, $value) => $q->where(fn ($inner) => $inner->where('sales.sale_number', 'ilike', '%'.$value.'%')->orWhereHas('receipt', fn ($receipt) => $receipt->where('receipts.receipt_number', 'ilike', '%'.$value.'%'))->orWhereHas('cashier', fn ($cashier) => $cashier->where('users.name', 'ilike', '%'.$value.'%'))));

        if (! empty($filters['from'])) {
            $query->where('sales.created_at', '>=', Carbon::createFromFormat('Y-m-d', $filters['from'], $timezone)->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('sales.created_at', '<=', Carbon::createFromFormat('Y-m-d', $filters['to'], $timezone)->endOfDay()->utc());
        }

        return $query;
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
