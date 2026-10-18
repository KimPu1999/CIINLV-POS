<?php

namespace App\Policies;

use App\Models\InventoryLocation;
use App\Models\User;

class InventoryPolicy
{
    public function view(User $user, InventoryLocation $location): bool
    {
        return $this->access($user, $location, false);
    }

    public function write(User $user, InventoryLocation $location): bool
    {
        return $this->access($user, $location, true);
    }

    private function access(User $user, InventoryLocation $location, bool $write): bool
    {
        if ($location->status !== 'active') {
            return false;
        }
        $role = $user->memberships()->where('tenant_id', $location->tenant_id)->where('status', 'active')->with('role')->first()?->role?->name;
        if ($role === 'business_admin') {
            return true;
        }
        if ($location->location_type === 'warehouse') {
            return $role === 'inventory_manager';
        }
        $assigned = $location->store?->storeMemberships()->where('user_id', $user->id)->where('status', 'active')->whereNull('revoked_at')->exists();

        return $assigned && (! $write || in_array($role, ['store_manager', 'inventory_manager'], true));
    }
}
