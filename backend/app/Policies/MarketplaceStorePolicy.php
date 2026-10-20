<?php

namespace App\Policies;

use App\Models\MarketplaceStore;
use App\Models\User;

class MarketplaceStorePolicy
{
    public function view(User $user, MarketplaceStore $store): bool
    {
        return $user->isSuperAdmin() || $this->businessAdminOwns($user, $store->tenant_id);
    }

    public function manage(User $user, MarketplaceStore $store): bool
    {
        return $this->businessAdminOwns($user, $store->tenant_id);
    }

    public function approve(User $user, MarketplaceStore $store): bool
    {
        return $user->isSuperAdmin();
    }

    private function businessAdminOwns(User $user, int $tenantId): bool
    {
        return ! $user->isSuperAdmin()
            && $user->isActive()
            && $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role', fn ($role) => $role->where('name', 'business_admin'))->exists();
    }
}
