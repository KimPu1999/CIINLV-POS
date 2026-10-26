<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class StoreMembershipService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function assign(Store $store, array $data, User $actor): StoreMembership
    {
        return DB::transaction(function () use ($store, $data, $actor): StoreMembership {
            $tenantMembership = TenantMembership::query()
                ->where('tenant_id', $store->tenant_id)
                ->where('user_id', $data['user_id'])
                ->where('status', 'active')
                ->whereHas('role', fn ($query) => $query->whereIn('name', ['business_admin', 'store_manager', 'inventory_manager', 'cashier', 'salesperson', 'sales_staff']))
                ->first();

            if (! $tenantMembership) {
                throw new UnprocessableEntityHttpException('The user must have an active membership in this business.');
            }

            if ($data['role'] === 'business_admin') {
                throw new UnprocessableEntityHttpException('Business administrator access is assigned at the business level.');
            }

            $duplicate = StoreMembership::query()
                ->where('store_id', $store->getKey())
                ->where('user_id', $data['user_id'])
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->exists();

            if ($duplicate) {
                throw new ConflictHttpException('This user is already assigned to this branch.');
            }

            $membership = StoreMembership::create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->getKey(),
                'user_id' => $data['user_id'],
                'role' => $data['role'],
                'status' => 'active',
                'assigned_by' => $actor->getKey(),
                'assigned_at' => now(),
            ]);

            $this->auditLogger->record('business.store_member_assigned', $actor, $store->tenant, $membership, [
                'store_id' => $store->uuid,
                'user_id' => $membership->user_id,
                'role' => $membership->role,
            ]);

            return $membership->load('user');
        });
    }

    public function update(StoreMembership $membership, array $data, User $actor): StoreMembership
    {
        if (isset($data['role']) && $data['role'] === 'business_admin') {
            throw new UnprocessableEntityHttpException('Business administrator access is assigned at the business level.');
        }

        $membership->update(array_filter([
            'role' => $data['role'] ?? null,
            'status' => $data['status'] ?? null,
            'revoked_at' => ($data['status'] ?? null) === 'revoked' ? now() : null,
        ], fn ($value) => $value !== null));
        $this->auditLogger->record('business.store_member_updated', $actor, $membership->tenant, $membership, ['role' => $membership->role, 'status' => $membership->status]);

        return $membership->fresh('user');
    }

    public function revoke(StoreMembership $membership, User $actor): void
    {
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->auditLogger->record('business.store_member_revoked', $actor, $membership->tenant, $membership, ['user_id' => $membership->user_id]);
    }
}
