<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InventoryAccessService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function tenantId(): int
    {
        return (int) $this->tenantContext->tenant()->getKey();
    }

    public function assertTenant(InventoryLocation $location): InventoryLocation
    {
        if ((int) $location->tenant_id !== $this->tenantId()) {
            throw new NotFoundHttpException('The requested inventory location was not found.');
        }

        return $location;
    }

    public function canAccess(User $user, InventoryLocation $location, bool $write = false): bool
    {
        if ((int) $location->tenant_id !== $this->tenantId() || $location->status !== 'active') {
            return false;
        }
        $membership = $user->memberships()->where('tenant_id', $location->tenant_id)->where('status', 'active')->with('role')->first();
        $role = $membership?->role?->name;
        if ($role === 'business_admin') {
            return true;
        }
        if ($location->location_type === 'warehouse') {
            return $role === 'inventory_manager' && ! $write || $role === 'inventory_manager';
        }
        if (! $location->store_id) {
            return false;
        }
        $assigned = $location->store->storeMemberships()->where('user_id', $user->getKey())->where('status', 'active')->whereNull('revoked_at')->exists();
        if (! $assigned) {
            return false;
        }
        if (! $write) {
            return true;
        }

        return in_array($role, ['store_manager', 'inventory_manager'], true);
    }

    public function authorize(User $user, InventoryLocation $location, bool $write = false): void
    {
        Gate::forUser($user)->authorize($write ? 'write' : 'view', $location);
    }
}
