<?php

namespace App\Http\Controllers\Api\V1;

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
        $membership = TenantMembership::query()->where('tenant_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->firstOrFail();

        [$invitation] = $invitationService->createPending($business, $user->email, 'business_admin', $request->user());
        $user->forceFill(['status' => User::STATUS_INVITED])->save();
        $membership->forceFill(['status' => 'pending', 'joined_at' => null])->save();
        $auditLogger->record('platform.business_invitation_resent', $request->user(), $business, $user);

        return response()->json(['success' => true, 'message' => 'Business invitation resent.', 'data' => ['invitation_status' => 'pending', 'expires_at' => $invitation->expires_at]]);
    }
}
