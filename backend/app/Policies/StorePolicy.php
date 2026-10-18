<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

class StorePolicy
{
    public function view(User $user, Store $store): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->tenants()->whereKey($store->tenant_id)->wherePivot('status', 'active')->exists()
            && $store->memberships()->where('user_id', $user->getKey())->wherePivot('status', 'active')->exists();
    }
}
