<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class TenantContext
{
    private ?Tenant $tenant = null;

    private ?TenantMembership $membership = null;

    public function resolveFor(Request $request, User $user): Tenant
    {
        return $this->resolve($request, $user, true);
    }

    public function resolveForAnyRole(Request $request, User $user): Tenant
    {
        return $this->resolve($request, $user, false);
    }

    private function resolve(Request $request, User $user, bool $requireBusinessAdmin): Tenant
    {
        if ($user->isSuperAdmin()) {
            throw new AccessDeniedHttpException('A tenant membership is required for this business endpoint.');
        }

        $memberships = $user->tenants()
            ->wherePivot('status', 'active')
            ->where('tenants.status', 'active')
            ->with('roles')
            ->get();

        $requestedUuid = $request->header('X-Tenant-Id');
        $tenant = $requestedUuid
            ? $memberships->firstWhere('uuid', $requestedUuid)
            : ($memberships->count() === 1 ? $memberships->first() : null);

        if (! $tenant) {
            if ($requestedUuid) {
                throw new AccessDeniedHttpException('You do not have access to the requested business.');
            }

            throw new BadRequestHttpException('X-Tenant-Id is required when the account belongs to multiple businesses.');
        }

        $membership = $user->tenants()
            ->whereKey($tenant->getKey())
            ->wherePivot('status', 'active')
            ->withPivot(['role_id', 'status', 'joined_at'])
            ->first();

        if (! $membership || $tenant->status !== 'active') {
            throw new AccessDeniedHttpException('Business access is not active.');
        }

        $this->tenant = $tenant;
        $this->membership = TenantMembership::query()
            ->whereKey($membership->pivot->id ?? null)
            ->with('role')
            ->first();

        if (! $this->membership) {
            $this->membership = TenantMembership::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('user_id', $user->getKey())
                ->with('role')
                ->firstOrFail();
        }

        if ($requireBusinessAdmin && $this->membership->role?->name !== 'business_admin') {
            throw new AccessDeniedHttpException('Business administrator access is required.');
        }

        App::instance(self::class, $this);

        return $tenant;
    }

    public function tenant(): Tenant
    {
        if (! $this->tenant) {
            throw new AccessDeniedHttpException('Tenant context has not been resolved.');
        }

        return $this->tenant;
    }

    public function membership(): TenantMembership
    {
        if (! $this->membership) {
            throw new AccessDeniedHttpException('Tenant membership has not been resolved.');
        }

        return $this->membership;
    }
}
