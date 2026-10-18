<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\AssignStoreMemberRequest;
use App\Http\Requests\Business\UpdateStoreMemberRequest;
use App\Http\Resources\StoreMembershipResource;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Services\StoreContextService;
use App\Services\StoreMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StoreMembershipController
{
    public function index(Store $store, StoreContextService $context): JsonResponse
    {
        $context->store($store);
        Gate::authorize('viewMembers', $store);

        return response()->json(['success' => true, 'data' => StoreMembershipResource::collection($store->storeMemberships()->with('user')->whereNull('revoked_at')->get())]);
    }

    public function store(AssignStoreMemberRequest $request, Store $store, StoreContextService $context, StoreMembershipService $service): JsonResponse
    {
        $context->store($store);
        Gate::authorize('manageMembers', $store);
        $membership = $service->assign($store, $request->validated(), $request->user());

        return response()->json(['success' => true, 'message' => 'Branch member assigned successfully.', 'data' => new StoreMembershipResource($membership)], 201);
    }

    public function update(UpdateStoreMemberRequest $request, Store $store, StoreMembership $membership, StoreContextService $context, StoreMembershipService $service): JsonResponse
    {
        $context->store($store);
        $this->assertMembershipBelongs($store, $membership);
        Gate::authorize('update', $membership);

        return response()->json(['success' => true, 'data' => new StoreMembershipResource($service->update($membership, $request->validated(), $request->user()))]);
    }

    public function destroy(Store $store, StoreMembership $membership, StoreContextService $context, Request $request, StoreMembershipService $service): JsonResponse
    {
        $context->store($store);
        $this->assertMembershipBelongs($store, $membership);
        Gate::authorize('delete', $membership);
        $service->revoke($membership, $request->user());

        return response()->json(['success' => true, 'message' => 'Branch access revoked.']);
    }

    private function assertMembershipBelongs(Store $store, StoreMembership $membership): void
    {
        abort_unless((int) $membership->store_id === (int) $store->getKey() && (int) $membership->tenant_id === (int) $store->tenant_id, 404);
    }
}
