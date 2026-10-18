<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pos\CashMovementRequest;
use App\Http\Requests\Pos\CloseShiftRequest;
use App\Http\Requests\Pos\OpenShiftRequest;
use App\Http\Resources\CashierShiftResource;
use App\Models\CashierShift;
use App\Models\PosRegister;
use App\Models\Store;
use App\Services\CashierShiftService;
use App\Services\PosAccessService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class CashierShiftController
{
    public function open(OpenShiftRequest $request, TenantContext $context, CashierShiftService $service): JsonResponse
    {
        $store = Store::where('tenant_id', $context->tenant()->id)->where('uuid', $request->input('store_id'))->firstOrFail();
        $register = PosRegister::where('tenant_id', $context->tenant()->id)->where('uuid', $request->input('register_id'))->firstOrFail();
        Gate::forUser($request->user())->authorize('operate', $register);
        $shift = $service->open($store, $register, $request->user(), (string) $request->input('opening_amount'));

        return response()->json(['success' => true, 'data' => new CashierShiftResource($shift)], 201);
    }

    public function current(Request $request, TenantContext $context): JsonResponse
    {
        $shift = CashierShift::with(['store', 'register', 'cashier'])->where('tenant_id', $context->tenant()->id)->where('cashier_user_id', $request->user()->id)->where('status', 'open')->first();

        return response()->json(['success' => true, 'data' => $shift ? new CashierShiftResource($shift) : null]);
    }

    public function index(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $storeIds = $context->tenant()->stores()->get()->filter(fn (Store $store) => $access->canAccessStore($request->user(), $store))->pluck('id');
        $query = CashierShift::with(['store', 'register', 'cashier'])->where('tenant_id', $context->tenant()->id)->whereIn('store_id', $storeIds)->latest('opened_at');
        if ($request->user()->memberships()->where('tenant_id', $context->tenant()->id)->whereHas('role', fn ($q) => $q->whereIn('name', ['cashier']))->exists()) {
            $query->where('cashier_user_id', $request->user()->id);
        }

        return response()->json(['success' => true, 'data' => CashierShiftResource::collection($query->paginate(min((int) $request->integer('per_page', 50), 100)))]);
    }

    public function close(CloseShiftRequest $request, CashierShift $shift, TenantContext $context, CashierShiftService $service): JsonResponse
    {
        $this->assertTenant($shift, $context);
        $closed = $service->close($shift, $request->user(), (string) $request->input('actual_amount'));

        return response()->json(['success' => true, 'data' => new CashierShiftResource($closed)]);
    }

    public function movement(CashMovementRequest $request, CashierShift $shift, TenantContext $context, CashierShiftService $service): JsonResponse
    {
        $this->assertTenant($shift, $context);
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw new UnprocessableEntityHttpException('Idempotency-Key is required for cash movements.');
        }
        $movement = $service->movement($shift, $request->user(), $request->input('type'), (string) $request->input('amount'), $request->input('reason'), $key);

        return response()->json(['success' => true, 'data' => $movement], 201);
    }

    private function assertTenant(CashierShift $shift, TenantContext $context): void
    {
        if ((int) $shift->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested shift was not found.');
        }
    }
}
