<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pos\CreateReturnRequest;
use App\Http\Resources\SaleReturnResource;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Store;
use App\Services\PosAccessService;
use App\Services\SaleReturnService;
use App\Services\TenantContext;
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
        $returns = SaleReturn::with(['sale', 'store'])->where('tenant_id', $context->tenant()->id)->whereIn('store_id', $storeIds)->latest()->paginate(min((int) $request->integer('per_page', 50), 100));

        return response()->json(['success' => true, 'data' => SaleReturnResource::collection($returns)]);
    }

    public function show(SaleReturn $return, TenantContext $context, Request $request): JsonResponse
    {
        $this->assertTenant($return, $context);
        Gate::forUser($request->user())->authorize('view', $return);

        return response()->json(['success' => true, 'data' => new SaleReturnResource($return->load(['items.saleItem', 'items.variant', 'sale', 'store', 'refund']))]);
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
