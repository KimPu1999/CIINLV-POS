<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pos\CreateRegisterRequest;
use App\Http\Requests\Pos\UpdateRegisterRequest;
use App\Http\Resources\PosRegisterResource;
use App\Models\CashierShift;
use App\Models\PosRegister;
use App\Models\Store;
use App\Services\AuditLogger;
use App\Services\PosAccessService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PosRegisterController
{
    public function index(TenantContext $context, PosAccessService $access, Request $request): JsonResponse
    {
        $stores = $context->tenant()->stores()->get()->filter(fn (Store $store) => $access->canAccessStore($request->user(), $store));
        $storeIds = $stores->pluck('id');
        if ($request->filled('store_id')) {
            $store = $stores->firstWhere('uuid', $request->string('store_id')->toString());
            $storeIds = $store ? collect([$store->id]) : collect();
        }
        $query = PosRegister::with('store')->where('tenant_id', $context->tenant()->id)->whereIn('store_id', $storeIds);
        if ($request->boolean('active_only')) {
            $query->where('status', 'active');
        }
        $registers = $query->orderBy('store_id')->orderBy('name')->get();

        return response()->json(['success' => true, 'data' => PosRegisterResource::collection($registers)]);
    }

    public function store(CreateRegisterRequest $request, TenantContext $context, PosAccessService $access, AuditLogger $audit): JsonResponse
    {
        $store = $this->resolveStore($request->input('store_id'), $context);
        abort_unless($access->canManage($request->user(), $store), 403, 'Register management access is required.');
        $register = PosRegister::create(['tenant_id' => $context->tenant()->id, 'store_id' => $store->id, 'name' => $request->input('name'), 'code' => $request->input('code'), 'settings' => $request->input('settings'), 'created_by' => $request->user()->id]);
        $audit->record('pos.register_created', $request->user(), $context->tenant(), $register, ['store_id' => $store->uuid]);

        return response()->json(['success' => true, 'data' => new PosRegisterResource($register->load('store'))], 201);
    }

    public function show(PosRegister $register, TenantContext $context, PosAccessService $access, Request $request): JsonResponse
    {
        $this->assertTenant($register, $context);
        Gate::forUser($request->user())->authorize('view', $register);

        return response()->json(['success' => true, 'data' => new PosRegisterResource($register->load('store'))]);
    }

    public function update(UpdateRegisterRequest $request, PosRegister $register, TenantContext $context, PosAccessService $access, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($register, $context);
        Gate::forUser($request->user())->authorize('manage', $register);
        $targetStore = $register->store;
        if ($request->filled('store_id')) {
            $targetStore = $this->resolveStore($request->input('store_id'), $context);
            abort_unless($access->canManage($request->user(), $targetStore), 403, 'Register management access is required for the selected branch.');
            if ((int) $targetStore->id !== (int) $register->store_id && CashierShift::where('tenant_id', $register->tenant_id)->where('register_id', $register->id)->where('status', 'open')->exists()) {
                throw new ConflictHttpException('Close the active cashier shift before moving this register to another branch.');
            }
        }
        if ($request->filled('status') && $request->input('status') !== 'active' && CashierShift::where('tenant_id', $register->tenant_id)->where('register_id', $register->id)->where('status', 'open')->exists()) {
            throw new ConflictHttpException('Close the active cashier shift before deactivating this register.');
        }
        $data = $request->validated();
        unset($data['store_id']);
        if ($request->filled('store_id')) {
            $data['store_id'] = $targetStore->id;
        }
        $register->update($data);
        $audit->record('pos.register_updated', $request->user(), $context->tenant(), $register);

        return response()->json(['success' => true, 'data' => new PosRegisterResource($register->fresh('store'))]);
    }

    private function resolveStore(string $uuid, TenantContext $context): Store
    {
        return Store::where('tenant_id', $context->tenant()->id)->where('uuid', $uuid)->firstOrFail();
    }

    private function assertTenant(PosRegister $register, TenantContext $context): void
    {
        if ((int) $register->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested register was not found.');
        }
    }
}
