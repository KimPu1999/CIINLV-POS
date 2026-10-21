<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Tenant;
use App\Models\Translation;
use App\Services\TenantContext;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TranslationController
{
    public function index(Request $request, TenantContext $context, TranslationService $translations): JsonResponse
    {
        $class = $translations->modelClass((string) $request->input('model_type'));
        $model = $this->resolveRecord($class, $request->input('record_id'), $context->tenant()->id);
        abort_unless($model, 404);
        $locale = $translations->locale($request);
        $rows = Translation::query()
            ->where('tenant_id', $context->tenant()->id)
            ->where('translatable_type', $class)
            ->where('translatable_id', $model->getKey())
            ->where('locale', $locale)
            ->get()
            ->keyBy('field');

        return response()->json(['success' => true, 'data' => collect($translations->fieldsFor($model))->mapWithKeys(function (string $field) use ($model, $rows, $locale): array {
            $row = $rows->get($field);

            return [$field => [
                'source_value' => $model->getAttribute($field),
                'value' => $row?->value,
                'locale' => $locale,
                'state' => $row?->state ?? 'pending',
                'failure_reason' => $row?->failure_reason,
            ]];
        })]);
    }

    public function update(Request $request, Translation $translation, TenantContext $context, TranslationService $translations): JsonResponse
    {
        abort_unless((int) $translation->tenant_id === (int) $context->tenant()->id, 404);
        abort_unless($request->user()->memberships()->where('tenant_id', $context->tenant()->id)->where('status', 'active')->whereHas('role', fn ($query) => $query->whereIn('name', ['business_admin', 'inventory_manager']))->exists(), 403);
        abort_unless(in_array($translation->field, $translations->fieldsFor($translation->translatable_type), true), 422);
        abort_unless($translation->locale !== $translation->source_locale, 422, 'Canonical source values cannot be edited as translations.');
        $data = $request->validate(['value' => ['required', 'string', 'max:10000']]);
        abort_unless(in_array($translation->locale, TranslationService::SUPPORTED_LOCALES, true), 422);

        $updated = $translations->updateManualTranslation($translation, $data['value'], (int) $request->user()->getKey());

        return response()->json(['success' => true, 'data' => [
            'id' => $updated->id,
            'locale' => $updated->locale,
            'field' => $updated->field,
            'value' => $updated->value,
            'state' => $updated->state,
            'reviewed_at' => $updated->reviewed_at,
        ]]);
    }

    private function resolveRecord(string $class, mixed $recordId, int $tenantId): mixed
    {
        if (! is_string($recordId) && ! is_int($recordId)) {
            return null;
        }

        return $class::query()
            ->when($class !== Tenant::class, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($class === Tenant::class, fn ($query) => $query->whereKey($tenantId))
            ->where(function ($query) use ($recordId): void {
                $query->where('uuid', (string) $recordId);
                if (ctype_digit((string) $recordId)) {
                    $query->orWhereKey((int) $recordId);
                }
            })
            ->first();
    }
}
