<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\CreateStoreRequest;
use App\Http\Requests\Business\UpdateStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\AuditLogger;
use App\Services\StoreContextService;
use App\Services\StoreProvisioningService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BusinessStoreController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $stores = $context->tenant()->stores()->orderBy('name')->get();
        if (! $request->user()->memberships()->where('tenant_id', $context->tenant()->getKey())->whereHas('role', fn ($q) => $q->where('name', 'business_admin'))->exists()) {
            $stores = $stores->filter(fn (Store $store) => Gate::forUser($request->user())->allows('view', $store))->values();
        }

        return response()->json(['success' => true, 'data' => StoreResource::collection($stores)]);
    }

    public function store(CreateStoreRequest $request, TenantContext $context, StoreProvisioningService $service): JsonResponse
    {
        abort_if($context->isPlatformScoped(), 403, 'Business Admin membership is required to create a branch.');
        abort_unless($context->membership()->role?->name === 'business_admin', 403, 'Business administrator access is required.');
        $store = $service->create($request->validated(), $context->tenant()->getKey(), $request->user());

        return response()->json(['success' => true, 'message' => 'Branch created successfully.', 'data' => new StoreResource($store)], 201);
    }

    public function show(Store $store, StoreContextService $storeContext): JsonResponse
    {
        $storeContext->store($store);
        Gate::authorize('view', $store);

        return response()->json(['success' => true, 'data' => new StoreResource($store)]);
    }

    public function update(UpdateStoreRequest $request, Store $store, StoreContextService $storeContext, AuditLogger $auditLogger): JsonResponse
    {
        $storeContext->store($store);
        Gate::authorize('manage', $store);
        $store->update($request->validated());
        $auditLogger->record('business.store_updated', $request->user(), $store->tenant, $store, ['store_id' => $store->uuid]);

        return response()->json(['success' => true, 'data' => new StoreResource($store->refresh())]);
    }

    public function activate(Store $store, StoreContextService $storeContext, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $storeContext->store($store);
        Gate::authorize('manage', $store);
        $store->update(['status' => 'active']);
        $auditLogger->record('business.store_activated', $request->user(), $store->tenant, $store);

        return response()->json(['success' => true, 'data' => new StoreResource($store->refresh())]);
    }

    public function suspend(Store $store, StoreContextService $storeContext, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $storeContext->store($store);
        Gate::authorize('manage', $store);
        $store->update(['status' => 'suspended']);
        $auditLogger->record('business.store_suspended', $request->user(), $store->tenant, $store);

        return response()->json(['success' => true, 'data' => new StoreResource($store->refresh())]);
    }

    public function setMain(Store $store, StoreContextService $storeContext, Request $request, StoreProvisioningService $service): JsonResponse
    {
        $storeContext->store($store);
        Gate::authorize('manage', $store);

        return response()->json(['success' => true, 'message' => 'Main branch updated.', 'data' => new StoreResource($service->setMain($store, $request->user()))]);
    }
}
