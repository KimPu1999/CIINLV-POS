<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAccessControl;

class WorkspaceAccessService
{
    public function control(Tenant|int $tenant, User|int $user): ?WorkspaceAccessControl
    {
        return WorkspaceAccessControl::query()
            ->where('tenant_id', $tenant instanceof Tenant ? $tenant->getKey() : $tenant)
            ->where('user_id', $user instanceof User ? $user->getKey() : $user)
            ->first();
    }

    public function accessMode(Tenant $tenant, User $user): string
    {
        if ($user->isSuperAdmin()) {
            return WorkspaceAccessControl::MODE_BOTH;
        }

        $role = $user->memberships()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', 'active')
            ->with('role')
            ->first()?->role?->name;

        // Existing administrators retain the original full workspace experience,
        // including before this table was introduced.
        if ($role === 'business_admin') {
            return WorkspaceAccessControl::MODE_BOTH;
        }

        return $this->control($tenant, $user)?->access_mode ?: WorkspaceAccessControl::MODE_BOTH;
    }

    public function preferredView(Tenant $tenant, User $user): string
    {
        $mode = $this->accessMode($tenant, $user);
        $requested = $this->control($tenant, $user)?->preferred_view ?: WorkspaceAccessControl::VIEW_COMBINED;

        if ($mode === WorkspaceAccessControl::MODE_BUSINESS_ONLY) {
            return WorkspaceAccessControl::VIEW_BUSINESS;
        }

        if ($mode === WorkspaceAccessControl::MODE_MARKETPLACE_ONLY) {
            return WorkspaceAccessControl::VIEW_MARKETPLACE;
        }

        return in_array($requested, [WorkspaceAccessControl::VIEW_BUSINESS, WorkspaceAccessControl::VIEW_MARKETPLACE, WorkspaceAccessControl::VIEW_COMBINED], true)
            ? $requested
            : WorkspaceAccessControl::VIEW_COMBINED;
    }

    public function canAccess(Tenant $tenant, User $user, string $workspace): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $mode = $this->accessMode($tenant, $user);

        return match ($workspace) {
            'business' => in_array($mode, [WorkspaceAccessControl::MODE_BUSINESS_ONLY, WorkspaceAccessControl::MODE_BOTH], true),
            'marketplace' => in_array($mode, [WorkspaceAccessControl::MODE_MARKETPLACE_ONLY, WorkspaceAccessControl::MODE_BOTH], true),
            default => false,
        };
    }

    public function normalizeView(string $mode, string $view): string
    {
        if ($mode === WorkspaceAccessControl::MODE_BUSINESS_ONLY) {
            return WorkspaceAccessControl::VIEW_BUSINESS;
        }

        if ($mode === WorkspaceAccessControl::MODE_MARKETPLACE_ONLY) {
            return WorkspaceAccessControl::VIEW_MARKETPLACE;
        }

        return $view;
    }
}
