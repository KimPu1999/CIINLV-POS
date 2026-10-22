<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

class ReportScopeService
{
    public function storeIds(User $user, Tenant $tenant, ?string $requestedUuid = null): Collection
    {
        $role = app(PosAccessService::class)->role($user, (int) $tenant->id);
        $query = Store::where('tenant_id', $tenant->id)->where('status', 'active');
        $platformScoped = $user->isSuperAdmin() && app(TenantContext::class)->allowsPlatformAccessTo($tenant);
        if ($role !== 'business_admin' && ! $platformScoped) {
            $query->whereIn('id', StoreMembership::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'active')->whereNull('revoked_at')->pluck('store_id'));
        }
        if ($requestedUuid !== null) {
            $query->where('uuid', $requestedUuid);
        }

        return $query->pluck('id');
    }

    public function locationIds(User $user, Tenant $tenant, ?string $requestedUuid = null): Collection
    {
        $stores = $this->storeIds($user, $tenant, $requestedUuid);
        $role = app(PosAccessService::class)->role($user, (int) $tenant->id);
        $platformScoped = $user->isSuperAdmin() && app(TenantContext::class)->allowsPlatformAccessTo($tenant);
        $query = InventoryLocation::where('tenant_id', $tenant->id)->where('status', 'active')->whereIn('store_id', $stores);
        if ($requestedUuid === null && ($role === 'business_admin' || $role === 'inventory_manager' || $platformScoped)) {
            $query->orWhere(fn ($q) => $q->where('tenant_id', $tenant->id)->where('status', 'active')->where('location_type', 'warehouse'));
        }
        if ($requestedUuid !== null && $stores->isEmpty()) {
            return collect();
        }

        return $query->pluck('id');
    }

    public function locationIdsForStoreIds(int $tenantId, array $storeIds, bool $includeWarehouses = true): Collection
    {
        $query = InventoryLocation::where('tenant_id', $tenantId)->whereIn('store_id', $storeIds);
        if ($includeWarehouses) {
            $query->orWhere(fn ($warehouse) => $warehouse->where('tenant_id', $tenantId)->where('location_type', 'warehouse')->whereNull('store_id'));
        }

        return $query->pluck('id');
    }
}
