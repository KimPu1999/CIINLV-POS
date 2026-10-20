<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\UpdateBusinessProfileRequest;
use App\Http\Resources\TenantResource;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Gate;

class BusinessProfileController
{
    public function show(TenantContext $context): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new TenantResource($context->tenant())]);
    }

    public function update(UpdateBusinessProfileRequest $request, TenantContext $context, AuditLogger $auditLogger, TranslationService $translations): JsonResponse
    {
        $tenant = $context->tenant();
        Gate::authorize('updateProfile', $tenant);
        $data = $request->validated();
        $sourceLocale = $data['source_locale'] ?? $request->input('lang', app()->getLocale());
        unset($data['source_locale']);
        $tenant->update($data);
        $translations->queueForModel($tenant->refresh(), $sourceLocale);
        $auditLogger->record('business.profile_updated', $request->user(), $tenant, $tenant);

        return response()->json(['success' => true, 'data' => new TenantResource($tenant->refresh())]);
    }

    public function uploadLogo(\App\Http\Requests\Business\UploadBusinessLogoRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $tenant = $context->tenant();
        Gate::authorize('updateProfile', $tenant);
        $oldPath = $tenant->logo_path;
        $file = $request->file('logo');
        $path = $file->storeAs('businesses/'.$tenant->uuid.'/branding', Str::uuid().'.'.$file->getClientOriginalExtension(), 'private');
        try { $tenant->forceFill(['logo_path' => $path])->save(); } catch (\Throwable $e) { Storage::disk('private')->delete($path); throw $e; }
        if ($oldPath && $oldPath !== $path) Storage::disk('private')->delete($oldPath);
        $auditLogger->record('business.logo_updated', $request->user(), $tenant);
        return response()->json(['success' => true, 'data' => new TenantResource($tenant->refresh())]);
    }

    public function removeLogo(Request $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $tenant = $context->tenant();
        Gate::authorize('updateProfile', $tenant);
        $oldPath = $tenant->logo_path;
        $tenant->forceFill(['logo_path' => null])->save();
        if ($oldPath) Storage::disk('private')->delete($oldPath);
        $auditLogger->record('business.logo_removed', $request->user(), $tenant);
        return response()->json(['success' => true, 'data' => new TenantResource($tenant->refresh())]);
    }

    public function logo(TenantContext $context)
    {
        $path = $context->tenant()->logo_path;
        abort_unless($path && Storage::disk('private')->exists($path), 404, 'Business logo not found.');
        return response()->file(Storage::disk('private')->path($path));
    }
}
