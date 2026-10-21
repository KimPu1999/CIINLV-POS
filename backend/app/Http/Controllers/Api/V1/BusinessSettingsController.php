<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\UpdateBusinessSettingsRequest;
use App\Http\Requests\Business\UpdateReceiptSettingsRequest;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BusinessSettingsController
{
    public function show(TenantContext $context): JsonResponse
    {
        $settings = $context->tenant()->businessSetting()->firstOrCreate(['tenant_id' => $context->tenant()->getKey()]);

        return response()->json(['success' => true, 'data' => $this->payload($settings)]);
    }

    public function update(UpdateBusinessSettingsRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $settings = $this->save($request, $context, $auditLogger, null, 'business.settings_updated');

        return response()->json(['success' => true, 'data' => $this->payload($settings)]);
    }

    public function receipt(TenantContext $context): JsonResponse
    {
        $settings = $context->tenant()->businessSetting()->firstOrCreate(['tenant_id' => $context->tenant()->getKey()]);

        return response()->json(['success' => true, 'data' => array_merge((array) $settings->receipt_configuration, ['revision' => $settings->revision])]);
    }

    public function updateReceipt(UpdateReceiptSettingsRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $tenant = $context->tenant();
        Gate::authorize('updateProfile', $tenant);
        $data = $request->validated();
        $settings = DB::transaction(function () use ($data, $tenant): object {
            $settings = $tenant->businessSetting()->lockForUpdate()->firstOrCreate(['tenant_id' => $tenant->getKey()]);
            if (array_key_exists('revision', $data) && (int) $data['revision'] !== (int) ($settings->revision ?: 1)) {
                throw new ConflictHttpException('These settings changed in another session. Reload before saving.');
            }
            unset($data['revision']);
            $settings->forceFill(['receipt_configuration' => array_merge((array) $settings->receipt_configuration, $data), 'revision' => (int) ($settings->revision ?: 1) + 1])->save();

            return $settings->refresh();
        });
        $auditLogger->record('business.receipt_settings_updated', $request->user(), $tenant, $settings);

        return response()->json(['success' => true, 'data' => array_merge((array) $settings->receipt_configuration, ['revision' => $settings->revision])]);
    }

    public function pos(UpdateBusinessSettingsRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($this->save($request, $context, $auditLogger, 'pos_configuration', 'business.pos_settings_updated'))]);
    }

    public function inventory(UpdateBusinessSettingsRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($this->save($request, $context, $auditLogger, 'inventory_configuration', 'business.inventory_settings_updated'))]);
    }

    public function paymentTax(UpdateBusinessSettingsRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($this->save($request, $context, $auditLogger, 'payment_configuration', 'business.payment_tax_settings_updated'))]);
    }

    public function appearance(UpdateBusinessSettingsRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($this->save($request, $context, $auditLogger, 'appearance_configuration', 'business.appearance_settings_updated'))]);
    }

    public function notificationConfiguration(UpdateBusinessSettingsRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($this->save($request, $context, $auditLogger, 'notification_configuration', 'business.notification_settings_updated'))]);
    }

    public function dataConfiguration(UpdateBusinessSettingsRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($this->save($request, $context, $auditLogger, 'data_configuration', 'business.data_settings_updated'))]);
    }

    public function subscription(TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();

        return response()->json(['success' => true, 'data' => ['plan' => $tenant->subscription_status, 'status' => $tenant->status, 'trial_start_at' => null, 'trial_expires_at' => null, 'billing_period' => null, 'features' => config('business.features', []), 'limits' => ['stores' => config('business.default_branch_limit'), 'products' => null, 'users' => null]]]);
    }

    private function payload($settings): array
    {
        $localization = (array) $settings->receipt_configuration;

        return array_merge($settings->toArray(), ['revision' => (int) ($settings->revision ?: 1), 'currency_code' => $settings->currency_code ?: 'MMK', 'timezone' => $settings->timezone ?: 'Asia/Yangon', 'locale' => $settings->locale ?: 'en', 'date_format' => $localization['date_format'] ?? 'DD/MM/YYYY', 'time_format' => $localization['time_format'] ?? '24', 'number_format' => $localization['number_format'] ?? '1,234.56', 'language' => $localization['language'] ?? ($settings->locale ?: 'en'), 'payment_provider_status' => 'not_configured', 'supported_payment_methods' => ['cash']]);
    }

    private function save(Request $request, TenantContext $context, AuditLogger $auditLogger, ?string $section, string $auditAction)
    {
        $tenant = $context->tenant();
        Gate::authorize('updateProfile', $tenant);
        $data = $request->validated();
        $settings = DB::transaction(function () use ($data, $tenant, $section): object {
            $settings = $tenant->businessSetting()->lockForUpdate()->first();
            if (! $settings) {
                $settings = $tenant->businessSetting()->create(['tenant_id' => $tenant->getKey()]);
            }
            if (array_key_exists('revision', $data) && (int) $data['revision'] !== (int) ($settings->revision ?: 1)) {
                throw new ConflictHttpException('These settings changed in another session. Reload before saving.');
            }
            $updates = [];
            if ($section === null) {
                $updates = array_intersect_key($data, array_flip(['currency_code', 'timezone', 'locale', 'tax_configuration', 'pos_configuration', 'inventory_configuration', 'payment_configuration', 'notification_configuration', 'appearance_configuration', 'data_configuration']));
                $localization = array_intersect_key($data, array_flip(['date_format', 'time_format', 'number_format', 'language']));
                if ($localization !== []) {
                    $updates['receipt_configuration'] = array_merge((array) $settings->receipt_configuration, $localization);
                }
                if (array_key_exists('language', $data) && ! array_key_exists('locale', $data)) {
                    $updates['locale'] = $data['language'];
                }
            } elseif (array_key_exists($section, $data)) {
                $updates[$section] = array_merge((array) $settings->{$section}, (array) $data[$section]);
            }
            $settings->forceFill($updates + ['revision' => (int) ($settings->revision ?: 1) + 1])->save();

            return $settings->refresh();
        });
        $auditLogger->record($auditAction, $request->user(), $tenant, $settings, ['revision' => $settings->revision, 'section' => $section]);

        return $settings;
    }
}
