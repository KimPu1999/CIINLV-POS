<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\UpdateStoreSettingsRequest;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Services\AuditLogger;
use App\Services\StoreContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreSettingsController
{
    public function show(Store $store, StoreContextService $context): JsonResponse
    {
        $context->store($store);
        Gate::authorize('viewSettings', $store);

        return response()->json(['success' => true, 'data' => $store->setting ?? StoreSetting::firstOrCreate(['tenant_id' => $store->tenant_id, 'store_id' => $store->getKey()])]);
    }

    public function update(UpdateStoreSettingsRequest $request, Store $store, StoreContextService $context, AuditLogger $auditLogger): JsonResponse
    {
        $context->store($store);
        Gate::authorize('manageSettings', $store);
        $setting = StoreSetting::updateOrCreate(['store_id' => $store->getKey()], array_merge($request->validated(), ['tenant_id' => $store->tenant_id]));
        $auditLogger->record('business.store_settings_updated', $request->user(), $store->tenant, $setting, ['store_id' => $store->uuid]);

        return response()->json(['success' => true, 'data' => $setting->refresh()]);
    }
}
