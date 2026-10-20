<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\UpdateBusinessProfileRequest;
use App\Http\Requests\Business\UploadBusinessDocumentRequest;
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

    public function uploadDocument(UploadBusinessDocumentRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $tenant = $context->tenant();
        Gate::authorize('updateProfile', $tenant);
        $type = $request->string('document_type')->toString();
        $file = $request->file('document');
        $path = $file->storeAs('businesses/'.$tenant->uuid.'/verification', Str::uuid().'.'.$file->extension(), 'private');

        if ($type === 'admin_profile_image') {
            $oldPath = $request->user()->profile_image_path;
            $request->user()->forceFill(['profile_image_path' => $path])->save();
            $event = 'business.admin_profile_image_updated';
        } else {
            $column = $type.'_path';
            $oldPath = $tenant->{$column};
            $tenant->forceFill([$column => $path, 'verification_status' => 'pending'])->save();
            $event = 'business.verification_document_updated';
        }

        if ($oldPath && $oldPath !== $path) Storage::disk('private')->delete($oldPath);
        $auditLogger->record($event, $request->user(), $tenant, metadata: ['document_type' => $type]);

        return response()->json(['success' => true, 'data' => new TenantResource($tenant->refresh())]);
    }

    public function document(Request $request, TenantContext $context, string $document)
    {
        $tenant = $context->tenant();
        Gate::authorize('view', $tenant);
        if ($document === 'admin_profile_image') {
            $path = $request->user()->profile_image_path;
        } else {
            abort_unless(in_array($document, ['id_front_image', 'id_back_image', 'company_certificate_image'], true), 404);
            $path = $tenant->{$document.'_path'};
        }
        abort_unless($path && Storage::disk('private')->exists($path), 404, 'Verification document not found.');
        return response()->file(Storage::disk('private')->path($path));
    }

    public function removeDocument(Request $request, TenantContext $context, AuditLogger $auditLogger, string $document): JsonResponse
    {
        $tenant = $context->tenant();
        Gate::authorize('updateProfile', $tenant);

        if ($document === 'admin_profile_image') {
            $path = $request->user()->profile_image_path;
            $request->user()->forceFill(['profile_image_path' => null])->save();
            $event = 'business.admin_profile_image_removed';
        } else {
            abort_unless(in_array($document, ['id_front_image', 'id_back_image', 'company_certificate_image'], true), 404);
            $column = $document.'_path';
            $path = $tenant->{$column};
            $tenant->forceFill([$column => null, 'verification_status' => 'pending'])->save();
            $event = 'business.verification_document_removed';
        }

        if ($path) Storage::disk('private')->delete($path);
        $auditLogger->record($event, $request->user(), $tenant, metadata: ['document_type' => $document]);

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
