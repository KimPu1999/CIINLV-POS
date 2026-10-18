<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\AcceptBusinessInvitationRequest;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use App\Services\AuditLogger;
use App\Services\BusinessInvitationService;
use Illuminate\Http\JsonResponse;

class BusinessInvitationController
{
    public function accept(AcceptBusinessInvitationRequest $request, BusinessInvitationService $invitationService, AuditLogger $auditLogger): JsonResponse
    {
        [$user, $tenant] = $invitationService->accept(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
            $auditLogger,
        );

        return response()->json(['success' => true, 'message' => 'Business invitation accepted.', 'data' => [
            'user' => new UserResource($user),
            'business' => new TenantResource($tenant),
        ]]);
    }
}
