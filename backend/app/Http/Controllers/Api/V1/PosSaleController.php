<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pos\CreateSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\PosRegister;
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
        $sales = Sale::with(['store', 'register'])->where('tenant_id', $context->tenant()->id)->whereIn('store_id', $storeIds)->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))->latest()->paginate(min((int) $request->integer('per_page', 50), 100));

        return response()->json(['success' => true, 'data' => SaleResource::collection($sales)]);
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

        return response()->json(['success' => true, 'data' => new SaleResource($sale->load(['items.variant', 'payments', 'store', 'register']))]);
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
