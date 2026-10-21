<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\BusinessInvitationResource;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BusinessInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformBusinessInvitationController
{
    public function resend(Tenant $business, User $user, Request $request, BusinessInvitationService $invitationService, AuditLogger $auditLogger): JsonResponse
    {
        abort_if($business->status === 'archived', 409, 'Archived businesses cannot receive invitations.');
        $membership = TenantMembership::query()->where('tenant_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->firstOrFail();

        if ($membership->status === 'active') {
            return response()->json(['message' => 'This business administrator is already active.'], 422);
        }

        [$invitation] = $invitationService->createPending($business, $user->email, 'business_admin', $request->user());
        if (! $user->isActive()) {
            $user->forceFill(['status' => User::STATUS_INVITED])->save();
        }
        $membership->forceFill(['status' => 'pending', 'joined_at' => null])->save();
        $auditLogger->record('platform.business_invitation_resent', $request->user(), $business, $user);

        return response()->json(['success' => true, 'message' => 'Business invitation resent.', 'data' => new BusinessInvitationResource($invitation->load(['tenant', 'user']))]);
    }

    public function revoke(Tenant $business, User $user, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $membership = TenantMembership::query()->where('tenant_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->firstOrFail();

        $invitation = $business->invitations()
            ->where('email', strtolower($user->email))
            ->where('role', 'business_admin')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();

        if (! $invitation) {
            return response()->json(['message' => 'No pending invitation exists for this administrator.'], 422);
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
        $membership->forceFill(['status' => 'revoked', 'joined_at' => null])->save();
        $auditLogger->record('platform.business_invitation_revoked', $request->user(), $business, $user);

        return response()->json(['success' => true, 'message' => 'Business invitation revoked.', 'data' => new BusinessInvitationResource($invitation->load(['tenant', 'user']))]);
    }
}
