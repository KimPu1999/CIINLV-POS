<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\BusinessInvitation;
use App\Models\Tenant;
use App\Services\PlatformMonitoringService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformDashboardController
{
    public function __invoke(Request $request, PlatformMonitoringService $monitoring): JsonResponse
    {
        $days = in_array((int) $request->input('days', 30), [7, 30, 90, 365], true) ? (int) $request->input('days', 30) : 30;
        $from = CarbonImmutable::now()->subDays($days - 1)->startOfDay();
        $now = CarbonImmutable::now();
        $pendingRegistrations = Tenant::where(fn ($query) => $query->where('approval_status', 'pending')->orWhere('status', 'pending'))->count();
        $pendingEmailVerification = Tenant::where('email_verification_status', 'pending_email_verification')->count();
        $pendingOwnerActivation = Tenant::whereHas('memberships', fn ($query) => $query->where('status', 'pending')->whereHas('role', fn ($role) => $role->where('name', 'business_admin')))->count();
        $pendingInvitations = BusinessInvitation::whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', $now)->where('role', 'business_admin')->count();
        $expiredInvitations = BusinessInvitation::whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<=', $now)->where('role', 'business_admin')->count();
        $health = $monitoring->systemHealth();
        $alerts = $this->alerts($pendingOwnerActivation, $expiredInvitations, $health);

        return response()->json(['success' => true, 'data' => [
            'range' => ['days' => $days, 'from' => $from->toIso8601String(), 'to' => $now->toIso8601String()],
            'kpis' => [
                'total_businesses' => Tenant::count(),
                'active_businesses' => Tenant::where('status', 'active')->count(),
                'trial_businesses' => Tenant::where('subscription_status', 'trial')->count(),
                'suspended_businesses' => Tenant::where('status', 'suspended')->count(),
                'pending_owner_activations' => $pendingOwnerActivation,
                'active_subscriptions' => Tenant::where('subscription_status', 'active')->count(),
                'new_businesses_this_month' => Tenant::whereBetween('created_at', [now()->startOfMonth(), $now])->count(),
                'expiring_subscriptions' => null,
                'confirmed_platform_mrr' => null,
                'confirmed_platform_arr' => null,
                'unresolved_platform_alerts' => count($alerts),
            ],
            'growth' => $this->growth($from, $days),
            'subscriptions' => [
                'status_distribution' => Tenant::select('subscription_status')->selectRaw('COUNT(*)::int AS count')->groupBy('subscription_status')->orderBy('subscription_status')->get()->map(fn ($row): array => ['status' => $row->subscription_status ?: 'unknown', 'count' => (int) $row->count])->values(),
                'billing_available' => false,
                'billing_message' => 'Recurring subscription amounts and verified SaaS billing collections are not stored in the current schema. Merchant POS payments are intentionally excluded.',
                'confirmed_collections' => null, 'mrr' => null, 'arr' => null, 'failed_billing_events' => null, 'overdue_billing' => null,
            ],
            'recent_businesses' => $this->recentBusinesses(),
            'pending_actions' => [
                'counts' => ['registrations' => $pendingRegistrations, 'owner_invitations' => $pendingInvitations, 'email_verification' => $pendingEmailVerification, 'owner_activation' => $pendingOwnerActivation, 'expired_invitations' => $expiredInvitations],
                'businesses' => Tenant::where(fn ($query) => $query->where('approval_status', 'pending')->orWhere('email_verification_status', 'pending_email_verification')->orWhere('status', 'pending'))->latest('created_at')->limit(8)->get(['id', 'uuid', 'name', 'business_code', 'status', 'approval_status', 'email_verification_status', 'created_at'])->map(fn (Tenant $tenant): array => ['id' => $tenant->uuid, 'name' => $tenant->name, 'business_code' => $tenant->business_code, 'status' => $tenant->status, 'approval_status' => $tenant->approval_status, 'email_verification_status' => $tenant->email_verification_status, 'created_at' => $tenant->created_at?->toIso8601String()])->values(),
            ],
            'activity' => $this->activity(), 'alerts' => $alerts, 'health' => $health,
        ]]);
    }

    private function growth(CarbonImmutable $from, int $days): array
    {
        $bucket = $days <= 30 ? 'day' : ($days <= 90 ? 'week' : 'month');
        $registrations = Tenant::where('created_at', '>=', $from)->selectRaw("DATE_TRUNC('{$bucket}', created_at) AS bucket, COUNT(*)::int AS count")->groupBy('bucket')->orderBy('bucket')->get()->map(fn ($row): array => ['period' => CarbonImmutable::parse($row->bucket)->toIso8601String(), 'count' => (int) $row->count])->values();
        $activations = Tenant::whereNotNull('approved_at')->where('approved_at', '>=', $from)->selectRaw("DATE_TRUNC('{$bucket}', approved_at) AS bucket, COUNT(*)::int AS count")->groupBy('bucket')->orderBy('bucket')->get()->map(fn ($row): array => ['period' => CarbonImmutable::parse($row->bucket)->toIso8601String(), 'count' => (int) $row->count])->values();

        return ['bucket' => $bucket, 'registrations' => $registrations, 'activated_businesses' => $activations, 'trial_to_paid_conversion' => null, 'churn' => null, 'historical_status_note' => 'Activation history is based on approved_at. Historical active/churn status is unavailable because status history is not stored.'];
    }

    private function recentBusinesses(): array
    {
        return Tenant::withCount('stores')->with(['memberships' => function ($query): void {
            $query->whereHas('role', fn ($role) => $role->where('name', 'business_admin'))->with(['user:id,name,email,email_verified_at', 'role:id,name']);
        }])->latest('created_at')->limit(8)->get()->map(function (Tenant $tenant): array {
            $ownerMembership = $tenant->memberships->sortByDesc(fn ($membership): int => $membership->status === 'active' ? 1 : 0)->first();
            $owner = $ownerMembership?->user;
            $ownerActivationStatus = $ownerMembership?->status === 'pending'
                ? 'pending'
                : ($owner?->email_verified_at ? 'verified' : ($tenant->email_verification_status ?: 'unknown'));

            return ['id' => $tenant->uuid, 'name' => $tenant->name, 'business_code' => $tenant->business_code, 'owner' => $owner ? ['name' => $owner->name, 'email' => $owner->email] : null, 'created_at' => $tenant->created_at?->toIso8601String(), 'subscription_status' => $tenant->subscription_status, 'status' => $tenant->status, 'owner_activation_status' => $ownerActivationStatus, 'branch_count' => (int) $tenant->stores_count];
        })->values()->all();
    }

    private function activity(): array
    {
        return AuditLog::with(['actor:id,name', 'tenant:id,name,business_code'])->where('action', 'like', 'platform.%')->latest('created_at')->limit(12)->get()->map(fn (AuditLog $log): array => ['id' => $log->id, 'action' => $log->action, 'actor' => $log->actor?->name, 'business' => $log->tenant ? ['name' => $log->tenant->name, 'business_code' => $log->tenant->business_code] : null, 'created_at' => $log->created_at?->toIso8601String()])->values()->all();
    }

    private function alerts(int $pendingOwnerActivation, int $expiredInvitations, array $health): array
    {
        $alerts = [];
        if ($pendingOwnerActivation > 0) $alerts[] = ['type' => 'owner_activation', 'severity' => 'warning', 'count' => $pendingOwnerActivation, 'label' => 'Owner activation pending'];
        if ($expiredInvitations > 0) $alerts[] = ['type' => 'expired_invitations', 'severity' => 'warning', 'count' => $expiredInvitations, 'label' => 'Owner invitations expired'];
        if (($health['recent_failed_jobs'] ?? 0) > 0) $alerts[] = ['type' => 'failed_jobs', 'severity' => 'critical', 'count' => $health['recent_failed_jobs'], 'label' => 'Failed queue jobs in the last 24 hours'];
        if (($health['checks']['postgresql']['status'] ?? null) === 'failed') $alerts[] = ['type' => 'database', 'severity' => 'critical', 'count' => 1, 'label' => 'PostgreSQL readiness check failed'];
        return $alerts;
    }
}
