<?php

namespace App\Policies;

use App\Models\StockAdjustment;
use App\Models\User;

class StockAdjustmentPolicy
{
    public function view(User $user, StockAdjustment $adjustment): bool
    {
        return $this->role($user, $adjustment->tenant_id) !== null;
    }

    public function manage(User $user, StockAdjustment $adjustment): bool
    {
        return in_array($this->role($user, $adjustment->tenant_id), ['business_admin', 'inventory_manager'], true);
    }

    private function role(User $user, int $tenantId): ?string
    {
        return $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->with('role')->first()?->role?->name;
    }
}
