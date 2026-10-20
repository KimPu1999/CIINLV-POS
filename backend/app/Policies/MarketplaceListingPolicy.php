<?php

namespace App\Policies;

use App\Models\MarketplaceListing;
use App\Models\User;

class MarketplaceListingPolicy
{
    public function view(User $user, MarketplaceListing $listing): bool
    {
        return $user->isSuperAdmin() || $this->businessAdminOwns($user, $listing->tenant_id);
    }

    public function manage(User $user, MarketplaceListing $listing): bool
    {
        return $this->businessAdminOwns($user, $listing->tenant_id);
    }

    private function businessAdminOwns(User $user, int $tenantId): bool
    {
        return ! $user->isSuperAdmin()
            && $user->isActive()
            && $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role', fn ($role) => $role->where('name', 'business_admin'))->exists();
    }
}
