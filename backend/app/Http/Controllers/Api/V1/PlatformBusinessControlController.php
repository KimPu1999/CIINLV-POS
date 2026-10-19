<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only, platform-authorized aggregate for the Business Control Center.
 *
 * This intentionally reports unsupported billing/entitlement/quota areas as
 * unavailable instead of fabricating plan data or treating merchant sales as
 * platform subscription revenue.
 */
class PlatformBusinessControlController
{
    public function show(Tenant $business): JsonResponse
    {
        Gate::authorize('manage', $business);

        $tenantId = $business->getKey();
        $memberships = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->with(['user:id,name,email,status,platform_role,last_login_at', 'role:id,name'])
            ->latest('id')
            ->get();

        $stores = Schema::hasTable('stores')
            ? DB::table('stores')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'uuid', 'name', 'code', 'status'])
            : collect();
        $warehouses = Schema::hasTable('inventory_locations')
            ? DB::table('inventory_locations')->where('tenant_id', $tenantId)->where('location_type', 'warehouse')->orderBy('name')->get(['id', 'uuid', 'name', 'code', 'status'])
            : collect();

        $activeMemberships = $memberships->where('status', 'active');
        $ownerMembership = $memberships->sortByDesc(fn (TenantMembership $membership): int => $membership->status === 'active' ? 1 : 0)->first();

        $audit = AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->with('actor:id,name,platform_role')
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->getKey(),
                'action' => $log->action,
                'actor' => $log->actor ? ['id' => $log->actor->getKey(), 'name' => $log->actor->name, 'role' => $log->actor->platform_role ?: 'business_user'] : null,
                'created_at' => $log->created_at?->toIso8601String(),
                'metadata' => $this->safeMetadata($log->metadata),
            ])->values();

        $activeSessions = $this->activeSessionCount($activeMemberships->pluck('user_id')->all());

        return response()->json(['success' => true, 'data' => [
            'business' => [
                'id' => $business->uuid,
                'name' => $business->name,
                'business_code' => $business->business_code,
                'status' => $business->status,
                'approval_status' => $business->approval_status,
                'email_verification_status' => $business->email_verification_status,
                'subscription_status' => $business->subscription_status,
                'email' => $business->email,
                'phone' => $business->phone,
                'country' => $business->country,
                'created_at' => $business->created_at?->toIso8601String(),
                'approved_at' => $business->approved_at?->toIso8601String(),
                'suspended_at' => $business->suspended_at?->toIso8601String(),
                'archived_at' => $business->archived_at?->toIso8601String(),
                'owner' => $ownerMembership?->user ? [
                    'id' => $ownerMembership->user->getKey(),
                    'name' => $ownerMembership->user->name,
                    'email' => $ownerMembership->user->email,
                    'status' => $ownerMembership->user->status,
                    'membership_status' => $ownerMembership->status,
                ] : null,
            ],
            'admins' => $memberships->map(fn (TenantMembership $membership): array => [
                'membership_id' => $membership->getKey(),
                'user' => $membership->user ? [
                    'id' => $membership->user->getKey(),
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'status' => $membership->user->status,
                    'platform_role' => $membership->user->platform_role,
                    'last_login_at' => $membership->user->last_login_at?->toIso8601String(),
                ] : null,
                'role' => $membership->role?->name,
                'status' => $membership->status,
                'joined_at' => $membership->joined_at?->toIso8601String(),
            ])->values(),
            'resources' => [
                'branches' => $stores->map(fn ($store): array => ['id' => $store->uuid ?: $store->id, 'name' => $store->name, 'code' => $store->code, 'status' => $store->status])->values(),
                'warehouses' => $warehouses->map(fn ($warehouse): array => ['id' => $warehouse->uuid ?: $warehouse->id, 'name' => $warehouse->name, 'code' => $warehouse->code, 'status' => $warehouse->status])->values(),
            ],
            'usage' => [
                'branches' => $stores->count(),
                'staff' => $activeMemberships->count(),
                'warehouses' => $warehouses->count(),
                'pos_terminals' => $this->tenantCount('pos_registers', $tenantId),
                'products' => $this->tenantCount('products', $tenantId),
                'inventory_locations' => $this->tenantCount('inventory_locations', $tenantId),
            ],
            'subscription' => [
                'status' => $business->subscription_status,
                'plan' => null,
                'renewal_at' => null,
                'grace_period_ends_at' => null,
                'billing_provider' => null,
                'data_available' => false,
                'message' => 'The current schema stores subscription_status only. Plans, renewal dates, invoices, and verified platform billing events are not configured.',
            ],
            'features' => $this->features(),
            'limits' => $this->limits($stores->count(), $activeMemberships->count(), $warehouses->count(), $this->tenantCount('pos_registers', $tenantId)),
            'security' => [
                'active_sessions' => $activeSessions,
                'mfa' => ['enabled' => false, 'status' => 'unavailable', 'message' => 'No MFA policy or enrollment table exists in the current schema.'],
                'session_scope' => $activeSessions === null ? 'unavailable' : 'database_browser_sessions_only',
            ],
            'audit' => $audit,
            'capabilities' => [
                'subscription_management' => false,
                'feature_entitlements' => false,
                'quota_enforcement' => false,
                'session_revocation' => Schema::hasTable('sessions') && config('session.driver') === 'database',
            ],
        ]]);
    }

    private function tenantCount(string $table, int $tenantId): int
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')
            ? (int) DB::table($table)->where($table.'.tenant_id', $tenantId)->count()
            : 0;
    }

    private function activeSessionCount(array $userIds): ?int
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable('sessions') || $userIds === []) {
            return config('session.driver') === 'database' && Schema::hasTable('sessions') ? 0 : null;
        }

        return (int) DB::table('sessions')
            ->whereIn('sessions.user_id', $userIds)
            ->where('sessions.last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->timestamp)
            ->count();
    }

    private function features(): array
    {
        return collect([
            ['key' => 'pos', 'label' => 'POS checkout', 'supported' => true],
            ['key' => 'products', 'label' => 'Products and catalog', 'supported' => true],
            ['key' => 'inventory', 'label' => 'Inventory operations', 'supported' => true],
            ['key' => 'purchases', 'label' => 'Purchasing and receiving', 'supported' => true],
            ['key' => 'transfers', 'label' => 'Stock transfers', 'supported' => true],
            ['key' => 'reports', 'label' => 'Reports and analytics', 'supported' => true],
            ['key' => 'multi_branch', 'label' => 'Multi-branch operations', 'supported' => true],
            ['key' => 'api_access', 'label' => 'API access', 'supported' => true],
        ])->map(fn (array $feature): array => $feature + [
            'entitled' => null,
            'enforced' => false,
            'status' => 'not_configured',
            'message' => 'No plan entitlement or explicit override is stored.',
        ])->all();
    }

    private function limits(int $branches, int $staff, int $warehouses, int $terminals): array
    {
        return [
            ['key' => 'branches', 'label' => 'Branches', 'used' => $branches, 'limit' => null, 'remaining' => null, 'enforced' => false],
            ['key' => 'staff', 'label' => 'Active staff memberships', 'used' => $staff, 'limit' => null, 'remaining' => null, 'enforced' => false],
            ['key' => 'warehouses', 'label' => 'Warehouses', 'used' => $warehouses, 'limit' => null, 'remaining' => null, 'enforced' => false],
            ['key' => 'pos_terminals', 'label' => 'POS terminals', 'used' => $terminals, 'limit' => null, 'remaining' => null, 'enforced' => false],
        ];
    }

    private function safeMetadata(?array $metadata): array
    {
        if (! $metadata) {
            return [];
        }
        $blocked = ['password', 'password_confirmation', 'token', 'plain_token', 'access_token', 'authorization', 'secret', 'api_key', 'payment_credentials'];
        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                $metadata[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $metadata[$key] = $this->safeMetadata($value);
            }
        }

        return $metadata;
    }
}
