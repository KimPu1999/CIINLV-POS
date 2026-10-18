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

        if ($store->status !== 'active' || ! $this->hasActiveTenantMembership($user, $store)) {
            return false;
        }

        if ($this->isBusinessAdmin($user, $store)) {
            return true;
        }

        return $this->hasActiveStoreMembership($user, $store);
    }

    public function manage(User $user, Store $store): bool
    {
        return $this->isBusinessAdmin($user, $store) && $this->hasActiveTenantMembership($user, $store);
    }

    public function manageMembers(User $user, Store $store): bool
    {
        return $this->manage($user, $store);
    }

    public function viewMembers(User $user, Store $store): bool
    {
        return $store->status === 'active' && ($this->isBusinessAdmin($user, $store)
            || ($this->hasActiveStoreMembership($user, $store) && $this->storeRole($user, $store) === 'store_manager'));
    }

    public function viewSettings(User $user, Store $store): bool
    {
        return $store->status === 'active' && ($this->isBusinessAdmin($user, $store)
            || ($this->hasActiveStoreMembership($user, $store) && $this->storeRole($user, $store) === 'store_manager'));
    }

    public function manageSettings(User $user, Store $store): bool
    {
        return $this->viewSettings($user, $store);
    }

    public function manageDashboard(User $user, Store $store): bool
    {
        return $this->manage($user, $store) || $this->view($user, $store);
    }

    public function viewDashboard(User $user, Store $store): bool
    {
        return $this->view($user, $store);
    }

    private function hasActiveTenantMembership(User $user, Store $store): bool
    {
        return $user->memberships()
            ->where('tenant_id', $store->tenant_id)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->whereIn('name', [
                'business_admin', 'store_manager', 'inventory_manager', 'cashier',
            ]))
            ->exists();
    }

    private function isBusinessAdmin(User $user, Store $store): bool
    {
        return $user->memberships()
            ->where('tenant_id', $store->tenant_id)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->exists();
    }

    private function hasActiveStoreMembership(User $user, Store $store): bool
    {
        return $store->storeMemberships()
            ->where('user_id', $user->getKey())
            ->where('tenant_id', $store->tenant_id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->exists();
    }

    private function storeRole(User $user, Store $store): ?string
    {
        return $store->storeMemberships()
            ->where('user_id', $user->getKey())
            ->where('tenant_id', $store->tenant_id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->value('role');
    }
}
