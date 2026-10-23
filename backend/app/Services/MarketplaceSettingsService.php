<?php

namespace App\Services;

use App\Models\MarketplaceCommissionRule;
use App\Models\MarketplaceSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class MarketplaceSettingsService
{
    public const COMMISSION_BASE_ITEM_SUBTOTAL = 'item_subtotal';

    public function defaults(): array
    {
        return [
            'enabled' => true,
            'seller_registration_enabled' => true,
            'seller_approval_required' => false,
            'listing_approval_required' => false,
            'maintenance_mode' => false,
            'currency_code' => strtoupper((string) config('pos.default_currency', 'MMK')),
            'default_commission_rate' => '0.0000',
            'commission_base' => self::COMMISSION_BASE_ITEM_SUBTOTAL,
            'payout_schedule' => 'manual',
            'payout_minimum' => '0.00',
            'policies' => [],
            'revision' => 1,
            'updated_at' => null,
            'updated_by' => null,
        ];
    }

    public function storageAvailable(): bool
    {
        return Schema::hasTable('marketplace_settings');
    }

    public function current(): array
    {
        $defaults = $this->defaults();
        if (! $this->storageAvailable()) {
            return ['configured' => false, 'settings' => $defaults];
        }

        $setting = MarketplaceSetting::query()->with('updatedBy:id,name,email')->first();
        if (! $setting) {
            return ['configured' => true, 'settings' => $defaults];
        }

        return [
            'configured' => true,
            'settings' => array_merge($defaults, [
                'enabled' => (bool) $setting->enabled,
                'seller_registration_enabled' => (bool) $setting->seller_registration_enabled,
                'seller_approval_required' => (bool) $setting->seller_approval_required,
                'listing_approval_required' => (bool) $setting->listing_approval_required,
                'maintenance_mode' => (bool) $setting->maintenance_mode,
                'currency_code' => $setting->currency_code,
                'default_commission_rate' => (string) $setting->default_commission_rate,
                'commission_base' => $setting->commission_base,
                'payout_schedule' => $setting->payout_schedule ?: $defaults['payout_schedule'],
                'payout_minimum' => (string) ($setting->payout_minimum ?? $defaults['payout_minimum']),
                'policies' => $setting->policies ?: [],
                'revision' => (int) $setting->revision,
                'updated_at' => $setting->updated_at?->toIso8601String(),
                'updated_by' => $setting->updatedBy ? ['name' => $setting->updatedBy->name, 'email' => $setting->updatedBy->email] : null,
            ]),
        ];
    }

    public function update(array $data, User $actor, MarketplaceAuditLogger $audit, ?int $expectedRevision = null): MarketplaceSetting
    {
        if (! $this->storageAvailable()) {
            throw new ServiceUnavailableHttpException(null, 'Marketplace settings storage is not available. Apply the reviewed marketplace settings migration before saving settings.');
        }

        return DB::transaction(function () use ($data, $actor, $audit, $expectedRevision): MarketplaceSetting {
            $setting = MarketplaceSetting::query()->lockForUpdate()->first();
            if ($setting && $expectedRevision !== null && (int) $setting->revision !== $expectedRevision) {
                throw new ConflictHttpException('These marketplace settings changed in another session. Reload before saving.');
            }
            $oldRate = $setting?->default_commission_rate;
            $reason = $data['reason'] ?? null;
            unset($data['reason']);
            $attributes = array_merge($this->defaults(), $data, ['updated_by' => $actor->id]);
            if (! Schema::hasColumn('marketplace_settings', 'payout_schedule')) {
                unset($attributes['payout_schedule'], $attributes['payout_minimum']);
            }
            if ($setting) {
                $attributes['revision'] = (int) $setting->revision + 1;
                $setting->forceFill($attributes)->save();
            } else {
                $setting = MarketplaceSetting::create($attributes);
            }

            if ($oldRate === null || bccomp((string) $oldRate, (string) $setting->default_commission_rate, 4) !== 0) {
                $this->recordDefaultRule((string) $setting->default_commission_rate, (string) $setting->commission_base, $actor);
            }

            $audit->record('marketplace.settings_updated', $actor, entity: $setting, metadata: [
                'revision' => $setting->revision,
                'changed_fields' => array_keys($data),
                'old_commission_rate' => $oldRate === null ? null : (string) $oldRate,
                'new_commission_rate' => (string) $setting->default_commission_rate,
                'commission_rule_type' => 'global_default',
                'reason' => $reason,
            ]);

            return $setting->refresh();
        });
    }

    public function assertAvailable(): void
    {
        $current = $this->current();
        $settings = $current['settings'];
        if (! $settings['enabled']) {
            throw new ServiceUnavailableHttpException(null, 'The marketplace is currently disabled.');
        }
        if ($settings['maintenance_mode']) {
            throw new ServiceUnavailableHttpException(null, 'The marketplace is temporarily under maintenance.');
        }
    }

    public function sellerRegistrationEnabled(): bool
    {
        return (bool) $this->current()['settings']['seller_registration_enabled'];
    }

    public function sellerApprovalRequired(): bool
    {
        return (bool) $this->current()['settings']['seller_approval_required'];
    }

    public function listingApprovalRequired(): bool
    {
        return (bool) $this->current()['settings']['listing_approval_required'];
    }

    private function recordDefaultRule(string $rate, string $base, User $actor): void
    {
        $now = CarbonImmutable::now();
        MarketplaceCommissionRule::query()
            ->whereNull('tenant_id')
            ->where('status', 'active')
            ->whereNull('effective_until')
            ->update(['status' => 'inactive', 'effective_until' => $now, 'updated_by' => $actor->id]);

        $attributes = [
            'tenant_id' => null,
            'rate' => $rate,
            'commission_base' => $base,
            'status' => 'active',
            'effective_from' => $now,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ];
        if (Schema::hasColumn('marketplace_commission_rules', 'rule_type')) {
            $attributes['rule_type'] = 'global';
            $attributes['category_id'] = null;
        }
        MarketplaceCommissionRule::create($attributes);
    }
}
