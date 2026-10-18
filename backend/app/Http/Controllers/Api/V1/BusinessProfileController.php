<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\UpdateBusinessProfileRequest;
use App\Http\Resources\TenantResource;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BusinessProfileController
{
    public function show(TenantContext $context): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new TenantResource($context->tenant())]);
    }

    public function update(UpdateBusinessProfileRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $tenant = $context->tenant();
        Gate::authorize('updateProfile', $tenant);
        $tenant->update($request->validated());
        $auditLogger->record('business.profile_updated', $request->user(), $tenant, $tenant);

        return response()->json(['success' => true, 'data' => new TenantResource($tenant->refresh())]);
    }
}
