<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\AcceptBusinessInvitationRequest;
use App\Http\Resources\TenantResource;
use App\Http\Resources\BusinessInvitationResource;
use App\Http\Resources\UserResource;
use App\Services\AuditLogger;
use App\Services\BusinessInvitationService;
use Illuminate\Http\JsonResponse;

class BusinessInvitationController
{
    public function show(string $token, BusinessInvitationService $invitationService): JsonResponse
    {
        $invitation = $invitationService->details($token);

        return response()->json(['success' => true, 'data' => new BusinessInvitationResource($invitation)]);
    }

    public function accept(AcceptBusinessInvitationRequest $request, BusinessInvitationService $invitationService, AuditLogger $auditLogger): JsonResponse
    {
        [$user, $tenant] = $invitationService->accept(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
            $request->user('sanctum'),
            $auditLogger,
        );

        return response()->json(['success' => true, 'message' => 'Business invitation accepted.', 'data' => [
            'user' => new UserResource($user),
            'business' => new TenantResource($tenant),
        ]]);
    }
}
