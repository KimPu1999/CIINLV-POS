<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Platform\CreateInvitedBusinessAdminRequest;
use App\Http\Requests\Platform\UpdateBusinessAdminRequest;
use App\Http\Resources\BusinessMembershipResource;
use App\Http\Resources\BusinessInvitationResource;
use App\Models\BusinessInvitation;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BusinessMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformBusinessAdminController
{
    public function businessIndex(Tenant $business): JsonResponse
    {
        $members = $business->memberships()->with(['user', 'role'])
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->latest()->get();

        $invitations = BusinessInvitation::query()
            ->where('tenant_id', $business->getKey())
            ->whereIn('email', $members->pluck('user.email')->filter()->values())
            ->latest('id')
            ->get()
            ->unique('email')
            ->keyBy('email');

        return response()->json(['success' => true, 'data' => $members->map(function (TenantMembership $membership) use ($invitations): array {
            $data = (new BusinessMembershipResource($membership))->resolve();
            $invitation = $invitations->get($membership->user?->email);
            $data['invitation'] = $invitation
                ? (new BusinessInvitationResource($invitation->load(['tenant', 'user'])))->resolve()
                : null;

            return $data;
        })->values()]);
    }

    public function businessStore(CreateInvitedBusinessAdminRequest $request, Tenant $business, BusinessMembershipService $membershipService): JsonResponse
    {
        [$user, $membership, $invitation] = $membershipService->inviteBusinessAdmin(
            $business,
            $request->validated('name'),
            $request->validated('email'),
            $request->user(),
        );

        $data = [
            'user' => new UserResource($user),
            'membership' => new BusinessMembershipResource($membership->load(['user', 'role'])),
            'invitation_status' => $invitation->refresh()->status(),
            'invitation' => new BusinessInvitationResource($invitation->load(['tenant', 'user'])),
        ];
        return response()->json([
            'success' => true,
            'message' => 'Business administrator invitation created successfully',
            'data' => $data,
        ], 201);
    }

    public function businessUpdate(UpdateBusinessAdminRequest $request, Tenant $business, User $user, AuditLogger $auditLogger): JsonResponse
    {
        $membership = TenantMembership::query()->where('tenant_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->firstOrFail();
        $data = $request->validated();
        $user->update($request->safe()->only(['name'])->toArray());

        if (array_key_exists('status', $data)) {
            $status = $data['status'];
            $membership->update(['status' => $status === 'active' ? 'active' : 'revoked']);
            $user->update(['status' => $status]);
            if ($status !== 'active') {
                $user->tokens()->delete();
            }
        }
        $auditLogger->record('platform.business_admin_updated', $request->user(), $business, $user);

        return response()->json(['success' => true, 'data' => new UserResource($user->refresh())]);
    }

    public function businessDestroy(Tenant $business, User $user, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $membership = TenantMembership::query()->where('tenant_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->firstOrFail();
        $membership->update(['status' => 'revoked']);
        $user->tokens()->delete();
        $auditLogger->record('platform.business_admin_revoked', $request->user(), $business, $user);

        return response()->json(['success' => true, 'message' => 'Business administrator access revoked.']);
    }

    public function index(Tenant $tenant): JsonResponse
    {
        $members = $tenant->memberships()->with(['user', 'role'])
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $members->map(fn (TenantMembership $membership): array => [
                'membership_id' => $membership->id,
                'user' => new UserResource($membership->user),
                'role' => $membership->role?->name,
                'status' => $membership->status,
            ]),
        ]);
    }

    public function store(CreateInvitedBusinessAdminRequest $request, Tenant $tenant, BusinessMembershipService $membershipService, AuditLogger $auditLogger): JsonResponse
    {
        [$user, $membership, $invitation] = $membershipService->inviteBusinessAdmin(
            $tenant,
            $request->validated('name'),
            $request->validated('email'),
            $request->user(),
        );

        $auditLogger->record('platform.business_admin_created', $request->user(), $tenant, $user);
        $response = [
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'membership_id' => $membership->id,
                'invitation_sent' => true,
                'invitation_status' => $invitation->refresh()->status(),
                'invitation' => new BusinessInvitationResource($invitation->load(['tenant', 'user'])),
            ],
        ];

        return response()->json($response, 201);
    }

    public function update(UpdateBusinessAdminRequest $request, Tenant $tenant, User $user, AuditLogger $auditLogger): JsonResponse
    {
        $membership = TenantMembership::query()->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->firstOrFail();

        $user->update($request->validated(['name']));
        if ($request->has('status')) {
            $status = $request->validated('status');
            $membership->update(['status' => $status === 'active' ? 'active' : 'revoked']);
            $user->update(['status' => $status]);
        }
        $auditLogger->record('platform.business_admin_updated', $request->user(), $tenant, $user);

        return response()->json(['success' => true, 'data' => new UserResource($user->refresh())]);
    }

    public function destroy(Tenant $tenant, User $user, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $membership = TenantMembership::query()->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->firstOrFail();
        $membership->update(['status' => 'revoked']);
        $user->tokens()->delete();
        $auditLogger->record('platform.business_admin_revoked', $request->user(), $tenant, $user);

        return response()->json(['success' => true, 'message' => 'Business administrator access revoked.']);
    }
}
