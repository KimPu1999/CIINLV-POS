<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin() || $user->tenants()->whereKey($tenant->getKey())->wherePivot('status', 'active')->exists();
    }

    public function manage(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin();
    }
}
