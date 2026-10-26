<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\UpdateBusinessInformationRequest;
use App\Http\Requests\Business\UploadBusinessInformationDocumentRequest;
use App\Http\Resources\StoreResource;
use App\Http\Resources\TenantResource;
use App\Models\BusinessDocument;
use App\Models\BusinessSetting;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BusinessInformationController
{
    public function show(Request $request, TenantContext $context): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($request, $context->tenant())]);
    }

    public function update(UpdateBusinessInformationRequest $request, TenantContext $context, AuditLogger $auditLogger, TranslationService $translations): JsonResponse
    {
        $tenant = $context->tenant();
        Gate::authorize('updateProfile', $tenant);
        $data = $request->validated();

        [$updatedTenant, $settings] = DB::transaction(function () use ($data, $tenant): array {
            $lockedTenant = $tenant->newQuery()->lockForUpdate()->findOrFail($tenant->getKey());
            $settings = $lockedTenant->businessSetting()->lockForUpdate()->first();
            if (! $settings) {
                $settings = $lockedTenant->businessSetting()->create(['tenant_id' => $lockedTenant->getKey()]);
            }

            if (array_key_exists('revision', $data) && (int) $data['revision'] !== (int) ($settings->revision ?: 1)) {
                abort(409, 'These business information settings changed in another session. Reload before saving.');
            }

            $profileKeys = [
                'name', 'display_name', 'description', 'business_type', 'email', 'website', 'phone', 'address',
                'city', 'state', 'postal_code', 'country', 'id_type', 'id_number', 'company_registration_number',
                'personal_registration_number', 'tax_registration_number',
            ];
            $profile = array_intersect_key($data['profile'] ?? [], array_flip($profileKeys));
            if ($profile !== []) {
                $lockedTenant->forceFill($profile)->save();
            }

            $settingsData = $data['settings'] ?? [];
            $settingsKeys = ['currency_code', 'timezone', 'locale'];
            $updates = array_intersect_key($settingsData, array_flip($settingsKeys));
            foreach (['tax_configuration', 'payment_configuration', 'pos_configuration', 'appearance_configuration', 'data_configuration'] as $configurationKey) {
                if (array_key_exists($configurationKey, $settingsData)) {
                    $updates[$configurationKey] = array_merge((array) $settings->{$configurationKey}, (array) $settingsData[$configurationKey]);
                }
            }
            if (array_key_exists('receipt_configuration', $data)) {
                $updates['receipt_configuration'] = array_merge((array) $settings->receipt_configuration, $data['receipt_configuration']);
            }
            if (array_key_exists('settlement', $data) || ($data['clear_account_details'] ?? false)) {
                $settlement = array_merge((array) $settings->settlement_configuration, $data['settlement'] ?? []);
                if ($data['clear_account_details'] ?? false) {
                    unset($settlement['account_details']);
                }
                $updates['settlement_configuration'] = $settlement;
            }
            if (array_key_exists('language', $settingsData) && ! array_key_exists('locale', $settingsData)) {
                $updates['locale'] = $settingsData['language'];
            }
            if ($updates !== []) {
                $settings->forceFill($updates + ['revision' => (int) ($settings->revision ?: 1) + 1])->save();
            }

            return [$lockedTenant->refresh(), $settings->refresh()];
        });

        if (isset($data['profile'])) {
            $translations->queueForModel($updatedTenant, $request->input('source_locale', app()->getLocale()));
        }
        $auditLogger->record('business.information_updated', $request->user(), $updatedTenant, $settings, [
            'sections' => array_values(array_filter([
                isset($data['profile']) ? 'profile' : null,
                isset($data['settings']) ? 'settings' : null,
                isset($data['receipt_configuration']) ? 'receipt' : null,
                isset($data['settlement']) || ($data['clear_account_details'] ?? false) ? 'settlement' : null,
            ])),
            'revision' => $settings->revision,
        ]);

        return response()->json(['success' => true, 'data' => $this->payload($request, $updatedTenant)]);
    }

    public function uploadDocument(UploadBusinessInformationDocumentRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $tenant = $context->tenant();
        Gate::authorize('updateProfile', $tenant);
        $file = $request->file('document');
        $documentType = $request->string('document_type')->toString();
        $path = $file->storeAs('businesses/'.$tenant->uuid.'/documents/'.$documentType, Str::uuid().'.'.$file->extension(), 'private');

        try {
            $document = DB::transaction(fn (): BusinessDocument => BusinessDocument::create([
                'tenant_id' => $tenant->getKey(),
                'document_type' => $documentType,
                'original_name' => preg_replace('/[\r\n]+/', ' ', basename((string) $file->getClientOriginalName())) ?: 'business-document',
                'path' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => (int) $file->getSize(),
                'uploaded_by' => $request->user()?->getKey(),
            ]));
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        $auditLogger->record('business.document_uploaded', $request->user(), $tenant, $document, [
            'document_type' => $documentType,
            'document_id' => $document->uuid,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
        ]);

        return response()->json(['success' => true, 'data' => $this->documentPayload($document)], 201);
    }

    public function downloadDocument(Request $request, TenantContext $context, string $document)
    {
        $tenant = $context->tenant();
        Gate::authorize('view', $tenant);
        $record = BusinessDocument::query()->where('tenant_id', $tenant->getKey())->where('uuid', $document)->firstOrFail();
        abort_unless(Storage::disk('private')->exists($record->path), 404, 'Business document not found.');

        return Storage::disk('private')->download($record->path, $record->original_name, ['Content-Type' => $record->mime_type]);
    }

    public function deleteDocument(Request $request, TenantContext $context, AuditLogger $auditLogger, string $document): JsonResponse
    {
        $tenant = $context->tenant();
        Gate::authorize('updateProfile', $tenant);
        $record = BusinessDocument::query()->where('tenant_id', $tenant->getKey())->where('uuid', $document)->firstOrFail();
        $path = $record->path;
        $record->delete();
        Storage::disk('private')->delete($path);
        $auditLogger->record('business.document_deleted', $request->user(), $tenant, metadata: [
            'document_type' => $record->document_type,
            'document_id' => $record->uuid,
        ]);

        return response()->json(['success' => true, 'message' => 'Business document removed.']);
    }

    private function payload(Request $request, $tenant): array
    {
        $settings = $tenant->businessSetting()->firstOrCreate(['tenant_id' => $tenant->getKey()]);
        $branches = $tenant->stores()
            ->with(['storeMemberships' => fn ($query) => $query->where('status', 'active')->whereNull('revoked_at')->where('role', 'store_manager')->with('user:id,name,email')])
            ->orderBy('name')
            ->get();
        $documents = BusinessDocument::query()->where('tenant_id', $tenant->getKey())->latest()->get();
        $settlement = (array) $settings->settlement_configuration;
        $accountDetails = (string) ($settlement['account_details'] ?? '');

        return [
            'profile' => (new TenantResource($tenant->refresh()))->resolve($request),
            'settings' => $settings->toArray() + ['revision' => (int) ($settings->revision ?: 1)],
            'receipt_configuration' => (array) $settings->receipt_configuration,
            'settlement' => [
                'bank_name' => $settlement['bank_name'] ?? null,
                'account_holder' => $settlement['account_holder'] ?? null,
                'settlement_method' => $settlement['settlement_method'] ?? null,
                'account_details_masked' => $accountDetails === '' ? null : str_repeat('•', max(0, mb_strlen($accountDetails) - 4)).mb_substr($accountDetails, -4),
                'configured' => $accountDetails !== '' || ! empty($settlement['bank_name']) || ! empty($settlement['account_holder']),
            ],
            'branches' => $branches->map(fn ($branch): array => array_merge((new StoreResource($branch))->resolve($request), [
                'manager' => $branch->storeMemberships->first()?->user ? [
                    'name' => $branch->storeMemberships->first()->user->name,
                    'email' => $branch->storeMemberships->first()->user->email,
                ] : null,
            ]))->values()->all(),
            'documents' => $documents->map(fn (BusinessDocument $document): array => $this->documentPayload($document))->values()->all(),
            'permissions' => ['can_update' => Gate::allows('updateProfile', $tenant)],
        ];
    }

    private function documentPayload(BusinessDocument $document): array
    {
        return [
            'id' => $document->uuid,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'created_at' => $document->created_at,
            'download_url' => route('business.information.documents.download', ['document' => $document->uuid]),
        ];
    }
}
