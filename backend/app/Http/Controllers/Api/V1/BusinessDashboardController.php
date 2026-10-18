<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TenantResource;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

class BusinessDashboardController
{
    public function __invoke(TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();

        return response()->json(['success' => true, 'data' => [
            'business' => new TenantResource($tenant),
            'active_members' => $tenant->memberships()->where('status', 'active')->count(),
            'active_stores' => $tenant->stores()->where('status', 'active')->count(),
            'pending_invitations' => $tenant->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
        ]]);
    }
}
