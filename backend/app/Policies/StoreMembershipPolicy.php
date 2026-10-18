<?php

namespace App\Policies;

use App\Models\StoreMembership;
use App\Models\User;

class StoreMembershipPolicy
{
    public function update(User $user, StoreMembership $membership): bool
    {
        return $this->manage($user, $membership);
    }

    public function delete(User $user, StoreMembership $membership): bool
    {
        return $this->manage($user, $membership);
    }

    private function manage(User $user, StoreMembership $membership): bool
    {
        return $user->memberships()
            ->where('tenant_id', $membership->tenant_id)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->exists();
    }
}
