<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Models\StoreMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserStoreController
{
    public function stores(Request $request): JsonResponse
    {
        $user = $request->user();
        $adminTenantIds = $user->memberships()->where('status', 'active')->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))->pluck('tenant_id');
        $directMemberships = StoreMembership::query()->where('user_id', $user->getKey())->where('status', 'active')->whereNull('revoked_at')->get()->keyBy('store_id');
        $stores = Store::query()->with('tenant')->where('status', 'active')->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->where(function ($query) use ($adminTenantIds, $directMemberships): void {
                $query->whereIn('tenant_id', $adminTenantIds)->orWhereIn('id', $directMemberships->keys());
            })->get();

        return response()->json(['success' => true, 'data' => $stores->map(function (Store $store) use ($adminTenantIds, $directMemberships): array {
            return ['store' => new StoreResource($store), 'business' => ['id' => $store->tenant->uuid, 'name' => $store->tenant->name], 'role' => $adminTenantIds->contains($store->tenant_id) ? 'business_admin' : $directMemberships->get($store->id)?->role];
        })->values()]);
    }

    public function permissions(Request $request): JsonResponse
    {
        $user = $request->user();
        $permissions = ['store.view', 'dashboard.view'];
        if ($user->isSuperAdmin()) {
            $permissions[] = 'platform.manage';
        }
        if ($user->memberships()->where('status', 'active')->whereHas('role', fn ($q) => $q->where('name', 'business_admin'))->exists()) {
            $permissions = array_merge($permissions, ['business.manage', 'store.manage', 'store.members.manage', 'dashboard.manage']);
        }

        return response()->json(['success' => true, 'data' => array_values(array_unique($permissions))]);
    }
}
