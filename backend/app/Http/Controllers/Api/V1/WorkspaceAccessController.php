<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BusinessInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WorkspaceAccessControl;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class WorkspaceAccessController
{
    public function show(Request $request, TenantContext $context, WorkspaceAccessService $access): JsonResponse
    {
        $tenant = $context->tenant();
        $user = $request->user();
        $mode = $access->accessMode($tenant, $user);
        $canManage = $this->canManage($user, $tenant->getKey());
        $validated = $request->validate([
            'email' => ['nullable', 'string', 'min:3', 'max:254', 'not_regex:/%/'],
        ]);
        $emailSearch = trim((string) ($validated['email'] ?? ''));

        // Team search is deliberately opt-in and manager-only. Returning every
        // tenant email on the initial Settings request made the endpoint an
        // enumeration surface and also hid pending invitations.
        $members = collect();
        if ($canManage && $emailSearch !== '') {
            $pattern = '%'.strtolower($emailSearch).'%';
            $memberships = TenantMembership::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereNotIn('status', ['revoked'])
                ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) LIKE ?', [$pattern]))
                ->with(['user:id,name,email,status', 'role:id,name'])
                ->orderBy('id')
                ->limit(25)
                ->get();

            $members = $memberships->map(function (TenantMembership $membership) use ($tenant, $access): array {
                $isActive = $membership->status === 'active' && $membership->user !== null;
                $isAdmin = $membership->role?->name === 'business_admin';

                return [
                    'user_id' => $membership->user_id,
                    'name' => $membership->user?->name,
                    'email' => $membership->user?->email,
                    'user_status' => $membership->user?->status,
                    'role' => $membership->role?->name,
                    'membership_status' => $membership->status === 'pending' ? 'invited' : $membership->status,
                    'access_mode' => $isActive ? $access->accessMode($tenant, $membership->user) : null,
                    'preferred_view' => $isActive ? $access->preferredView($tenant, $membership->user) : null,
                    'is_admin' => $isAdmin,
                    'can_update' => $isActive && ! $isAdmin,
                    'invitation_status' => null,
                    'invitation_id' => null,
                ];
            })->values();

            $invitations = BusinessInvitation::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->whereRaw('LOWER(email) LIKE ?', [$pattern])
                ->latest('id')
                ->limit(25)
                ->get();

            foreach ($invitations as $invitation) {
                $existingIndex = $members->search(fn (array $member): bool => strtolower((string) $member['email']) === strtolower($invitation->email));
                if ($existingIndex !== false) {
                    $member = $members->get($existingIndex);
                    $member['invitation_status'] = $invitation->status();
                    $member['invitation_id'] = $invitation->getKey();
                    $members->put($existingIndex, $member);
                    continue;
                }

                // An invitation without a tenant membership is still shown as
                // invitation-only. Never turn the email match into an account
                // or expose a user_id that is not a member of this tenant.
                $members->push([
                    'user_id' => null,
                    'name' => 'Invited member',
                    'email' => $invitation->email,
                    'user_status' => 'invited',
                    'role' => $invitation->role,
                    'membership_status' => 'invited',
                    'access_mode' => null,
                    'preferred_view' => null,
                    'is_admin' => $invitation->role === 'business_admin',
                    'can_update' => false,
                    'invitation_status' => $invitation->status(),
                    'invitation_id' => $invitation->getKey(),
                ]);
            }
        }

        return response()->json(['success' => true, 'data' => [
            'access_mode' => $mode,
            'preferred_view' => $access->preferredView($tenant, $user),
            'available_views' => $this->availableViews($mode),
            'can_manage' => $canManage,
            'members' => $members,
        ]]);
    }

    public function updatePreference(Request $request, TenantContext $context, WorkspaceAccessService $access, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['preferred_view' => ['required', Rule::in(['business', 'marketplace', 'combined'])]]);
        $tenant = $context->tenant();
        $user = $request->user();
        $mode = $access->accessMode($tenant, $user);
        $view = $access->normalizeView($mode, $data['preferred_view']);
        $control = WorkspaceAccessControl::query()->firstOrNew(['tenant_id' => $tenant->getKey(), 'user_id' => $user->getKey()]);
        $oldView = $control->exists ? $control->preferred_view : $access->preferredView($tenant, $user);
        // Persist the effective grant, not a stale restricted value that may
        // have been written before an administrator became an admin. Admins
        // retain both grants while their personal view remains selectable.
        $control->forceFill(['access_mode' => $mode, 'preferred_view' => $view, 'updated_by' => $user->getKey()])->save();
        $audit->record('workspace.preference_updated', $user, $tenant, $control, ['old_view' => $oldView, 'new_view' => $view]);

        return response()->json(['success' => true, 'data' => ['access_mode' => $mode, 'preferred_view' => $view, 'available_views' => $this->availableViews($mode)]]);
    }

    public function updateMember(Request $request, int $user, TenantContext $context, WorkspaceAccessService $access, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['access_mode' => ['required', Rule::in(['business_only', 'marketplace_only', 'both'])]]);
        $tenant = $context->tenant();
        $actor = $request->user();
        $this->assertManager($actor, $tenant->getKey());
        $membership = TenantMembership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $user)->where('status', 'active')->with(['user', 'role'])->firstOrFail();
        $oldMode = $access->accessMode($tenant, $membership->user);

        // A business administrator is the owner-level authorization boundary for
        // the existing application and cannot be hidden from either workspace.
        if ($membership->role?->name === 'business_admin' && $data['access_mode'] !== WorkspaceAccessControl::MODE_BOTH) {
            throw new AccessDeniedHttpException('Business administrators must retain access to both workspaces.');
        }

        DB::transaction(function () use ($tenant, $membership, $actor, $data): void {
            $control = WorkspaceAccessControl::query()->firstOrNew(['tenant_id' => $tenant->getKey(), 'user_id' => $membership->user_id]);
            $view = $data['access_mode'] === WorkspaceAccessControl::MODE_BUSINESS_ONLY ? WorkspaceAccessControl::VIEW_BUSINESS : ($data['access_mode'] === WorkspaceAccessControl::MODE_MARKETPLACE_ONLY ? WorkspaceAccessControl::VIEW_MARKETPLACE : WorkspaceAccessControl::VIEW_COMBINED);
            $control->forceFill(['access_mode' => $data['access_mode'], 'preferred_view' => $view, 'updated_by' => $actor->getKey()])->save();
        });

        $control = WorkspaceAccessControl::query()->where('tenant_id', $tenant->getKey())->where('user_id', $membership->user_id)->firstOrFail();
        $audit->record('workspace.access_updated', $actor, $tenant, $membership->user, ['target_user_id' => $membership->user_id, 'old_access_mode' => $oldMode, 'new_access_mode' => $control->access_mode]);

        return response()->json(['success' => true, 'data' => ['user_id' => $membership->user_id, 'access_mode' => $control->access_mode, 'preferred_view' => $control->preferred_view]]);
    }

    private function availableViews(string $mode): array
    {
        return match ($mode) {
            WorkspaceAccessControl::MODE_BUSINESS_ONLY => ['business'],
            WorkspaceAccessControl::MODE_MARKETPLACE_ONLY => ['marketplace'],
            default => ['business', 'marketplace', 'combined'],
        };
    }

    private function canManage(User $user, int $tenantId): bool
    {
        return $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))->exists();
    }

    private function assertManager(User $user, int $tenantId): void
    {
        if (! $this->canManage($user, $tenantId)) {
            throw new AccessDeniedHttpException('Business owner or administrator access is required.');
        }
    }
}
