<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

class DashboardPolicy
{
    public function view(User $user, Store $store): bool
    {
        return app(StorePolicy::class)->view($user, $store);
    }

    public function update(User $user, Store $store): bool
    {
        return app(StorePolicy::class)->manageDashboard($user, $store);
    }
}
