<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function view(User $user, Product $product): bool
    {
        return $this->role($user, $product->tenant_id) !== null;
    }

    public function manage(User $user, Product $product): bool
    {
        return in_array($this->role($user, $product->tenant_id), ['business_admin', 'inventory_manager'], true);
    }

    private function role(User $user, int $tenantId): ?string
    {
        return $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->with('role')->first()?->role?->name;
    }
}
