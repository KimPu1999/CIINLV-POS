<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function view(User $user, PurchaseOrder $order): bool
    {
        return $this->role($user, $order->tenant_id) !== null;
    }

    public function manage(User $user, PurchaseOrder $order): bool
    {
        return in_array($this->role($user, $order->tenant_id), ['business_admin', 'inventory_manager'], true);
    }

    private function role(User $user, int $tenantId): ?string
    {
        return $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->with('role')->first()?->role?->name;
    }
}
