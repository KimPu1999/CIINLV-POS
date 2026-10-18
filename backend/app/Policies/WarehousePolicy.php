<?php

namespace App\Policies;

use App\Models\InventoryLocation;
use App\Models\User;

class WarehousePolicy
{
    public function view(User $user, InventoryLocation $location): bool
    {
        return $this->role($user, $location->tenant_id) !== null;
    }

    public function manage(User $user, InventoryLocation $location): bool
    {
        return in_array($this->role($user, $location->tenant_id), ['business_admin', 'inventory_manager'], true);
    }

    private function role(User $user, int $tenantId): ?string
    {
        return $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->with('role')->first()?->role?->name;
    }
}
