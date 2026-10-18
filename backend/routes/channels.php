<?php

use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

$tenantAccess = static function (User $user, string $tenantUuid): bool {
    $tenant = Tenant::where('uuid', $tenantUuid)->first();

    return $tenant !== null && $tenant->status === 'active' && TenantMembership::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'active')->exists();
};

Broadcast::channel('tenant.{tenantUuid}.dashboard', $tenantAccess);
Broadcast::channel('tenant.{tenantUuid}.alerts', $tenantAccess);

$storeAccess = static function (User $user, string $tenantUuid, string $storeUuid) use ($tenantAccess): bool {
    if (! $tenantAccess($user, $tenantUuid)) {
        return false;
    }
    $tenant = Tenant::where('uuid', $tenantUuid)->first();
    $store = Store::where('tenant_id', $tenant?->id)->where('uuid', $storeUuid)->where('status', 'active')->first();
    if (! $store) {
        return false;
    }
    $role = TenantMembership::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'active')->with('role')->first()?->role?->name;

    return $role === 'business_admin' || StoreMembership::where('tenant_id', $tenant->id)->where('store_id', $store->id)->where('user_id', $user->id)->where('status', 'active')->whereNull('revoked_at')->exists();
};

Broadcast::channel('tenant.{tenantUuid}.store.{storeUuid}.sales', $storeAccess);
Broadcast::channel('tenant.{tenantUuid}.store.{storeUuid}.inventory', $storeAccess);
