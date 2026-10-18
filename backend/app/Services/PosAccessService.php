<?php

namespace App\Services;

use App\Models\PosRegister;
use App\Models\Store;
use App\Models\User;

class PosAccessService
{
    public function role(User $user, int $tenantId): ?string
    {
        return $user->memberships()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with('role')
            ->first()?->role?->name;
    }

    public function canAccessStore(User $user, Store $store): bool
    {
        if ($store->status !== 'active' || $store->tenant?->status !== 'active') {
            return false;
        }
        $role = $this->role($user, (int) $store->tenant_id);
        if ($role === 'business_admin') {
            return true;
        }

        return $store->storeMemberships()
            ->where('user_id', $user->id)
            ->where('tenant_id', $store->tenant_id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->exists();
    }

    public function canOperate(User $user, Store $store): bool
    {
        if (! $this->canAccessStore($user, $store)) {
            return false;
        }

        return in_array($this->role($user, (int) $store->tenant_id), ['business_admin', 'store_manager', 'cashier'], true);
    }

    public function canManage(User $user, Store $store): bool
    {
        if (! $this->canAccessStore($user, $store)) {
            return false;
        }

        return in_array($this->role($user, (int) $store->tenant_id), ['business_admin', 'store_manager'], true);
    }

    public function isBusinessAdmin(User $user, Store $store): bool
    {
        return $this->role($user, (int) $store->tenant_id) === 'business_admin'
            && $this->canAccessStore($user, $store);
    }

    public function canOperateRegister(User $user, PosRegister $register): bool
    {
        if (! $this->canOperate($user, $register->store)) {
            return false;
        }
        if ($this->role($user, (int) $register->tenant_id) !== 'cashier') {
            return true;
        }

        return $register->memberships()->where('user_id', $user->id)->where('status', 'active')->whereNull('revoked_at')->exists();
    }
}
