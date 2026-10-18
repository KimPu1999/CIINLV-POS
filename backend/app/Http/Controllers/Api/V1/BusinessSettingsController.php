<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\UpdateBusinessSettingsRequest;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BusinessSettingsController
{
    public function show(TenantContext $context): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $context->tenant()->businessSetting()->firstOrCreate(['tenant_id' => $context->tenant()->getKey()])]);
    }

    public function update(UpdateBusinessSettingsRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $tenant = $context->tenant();
        Gate::authorize('updateProfile', $tenant);
        $settings = $tenant->businessSetting()->firstOrCreate(['tenant_id' => $tenant->getKey()]);
        $settings->update($request->validated());
        $auditLogger->record('business.settings_updated', $request->user(), $tenant, $settings);

        return response()->json(['success' => true, 'data' => $settings->refresh()]);
    }
}
