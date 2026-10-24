<?php

namespace App\Services;

use App\Models\MarketplaceCommissionRule;
use App\Models\MarketplaceSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'qr_payment_provider' => null,
            'qr_payment_account_name' => null,
            'qr_payment_phone' => null,
            'qr_payment_instructions' => null,
            'qr_payment_qr_path' => null,
            'qr_payment_enabled' => false,
            'revision' => 1,
            'updated_at' => null,
            'updated_by' => null,
        ];
    }

    public function appearanceDefaults(): array
    {
        return [
            'font_family' => 'Inter', 'nav_font_size' => 16, 'nav_font_weight' => '600',
            'body_font_size' => 16, 'heading_font_size' => 32, 'button_font_size' => 14, 'footer_font_size' => 14,
            'light' => ['primary' => '#003868', 'secondary' => '#f86820', 'accent' => '#47bfff', 'header_bg' => '#003868', 'nav_text' => '#ffffff', 'nav_hover' => '#164f7d', 'nav_active' => '#1f6ea8', 'button_bg' => '#2563eb', 'button_text' => '#ffffff', 'page_bg' => '#f8fafc', 'card_bg' => '#ffffff', 'border' => '#dbeafe', 'footer_bg' => '#002850', 'footer_text' => '#ffffff'],
            'dark' => ['primary' => '#47bfff', 'secondary' => '#f86820', 'accent' => '#7dd3fc', 'header_bg' => '#002850', 'nav_text' => '#ffffff', 'nav_hover' => '#164f7d', 'nav_active' => '#1f6ea8', 'button_bg' => '#2563eb', 'button_text' => '#ffffff', 'page_bg' => '#020617', 'card_bg' => '#0f172a', 'border' => '#334155', 'footer_bg' => '#020617', 'footer_text' => '#ffffff'],
            'footer' => [
                'show_social_icons' => false,
                'facebook_url' => null,
                'instagram_url' => null,
                'youtube_url' => null,
                'tiktok_url' => null,
                'description_en' => 'A customer-first marketplace for CIINLV POS businesses.',
                'description_my' => 'CIINLV POS လုပ်ငန်းများအတွက် ဖောက်သည်ကို ဦးစားပေးထားသော Marketplace။',
            ],
        ];
    }

    public function appearance(): array
    {
        $defaults = $this->appearanceDefaults();
        if (! $this->appearanceStorageAvailable()) {
            return ['configured' => false, 'draft' => $defaults, 'published' => $defaults, 'revision' => 1, 'published_at' => null];
        }
        $setting = MarketplaceSetting::query()->first();
        $draft = array_replace_recursive($defaults, $setting?->appearance_draft ?: $defaults);
        $published = array_replace_recursive($defaults, $setting?->appearance_published ?: $defaults);

        return ['configured' => true, 'draft' => $draft, 'published' => $published, 'revision' => (int) ($setting?->appearance_revision ?: 1), 'published_at' => $setting?->appearance_published_at?->toIso8601String()];
    }

    public function updateAppearance(array $appearance, User $actor, MarketplaceAuditLogger $audit): array
    {
        if (! $this->appearanceStorageAvailable()) {
            throw new ServiceUnavailableHttpException(null, 'Marketplace appearance storage is not available. Apply the reviewed marketplace settings migration before saving appearance changes.');
        }
        $setting = MarketplaceSetting::query()->first() ?: MarketplaceSetting::create($this->defaults());
        $setting->forceFill(['appearance_draft' => array_replace_recursive($this->appearanceDefaults(), $appearance), 'appearance_revision' => (int) $setting->appearance_revision + 1, 'updated_by' => $actor->id])->save();
        $audit->record('marketplace.appearance_draft_updated', $actor, entity: $setting, metadata: ['appearance_revision' => $setting->appearance_revision]);

        return $this->appearance();
    }

    public function publishAppearance(User $actor, MarketplaceAuditLogger $audit): array
    {
        if (! $this->appearanceStorageAvailable()) {
            throw new ServiceUnavailableHttpException(null, 'Marketplace appearance storage is not available. Apply the reviewed marketplace settings migration before publishing appearance changes.');
        }
        $setting = MarketplaceSetting::query()->first() ?: MarketplaceSetting::create($this->defaults());
        $appearance = array_replace_recursive($this->appearanceDefaults(), $setting->appearance_draft ?: $this->appearanceDefaults());
        $setting->forceFill(['appearance_published' => $appearance, 'appearance_published_at' => now(), 'appearance_published_by' => $actor->id, 'updated_by' => $actor->id])->save();
        $audit->record('marketplace.appearance_published', $actor, entity: $setting, metadata: ['appearance_revision' => $setting->appearance_revision]);

        return $this->appearance();
    }

    public function resetAppearance(User $actor, MarketplaceAuditLogger $audit): array
    {
        if (! $this->appearanceStorageAvailable()) {
            throw new ServiceUnavailableHttpException(null, 'Marketplace appearance storage is not available. Apply the reviewed marketplace settings migration before resetting appearance changes.');
        }
        $setting = MarketplaceSetting::query()->first() ?: MarketplaceSetting::create($this->defaults());
        $setting->forceFill(['appearance_draft' => $this->appearanceDefaults(), 'appearance_revision' => (int) $setting->appearance_revision + 1, 'updated_by' => $actor->id])->save();
        $audit->record('marketplace.appearance_draft_reset', $actor, entity: $setting, metadata: ['appearance_revision' => $setting->appearance_revision]);

        return $this->appearance();
    }

    public function storageAvailable(): bool
    {
        return Schema::hasTable('marketplace_settings');
    }

    public function appearanceStorageAvailable(): bool
    {
        if (! $this->storageAvailable()) {
            return false;
        }

        foreach (['appearance_draft', 'appearance_published', 'appearance_revision', 'appearance_published_at', 'appearance_published_by'] as $column) {
            if (! Schema::hasColumn('marketplace_settings', $column)) {
                return false;
            }
        }

        return true;
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
                'qr_payment_provider' => $setting->qr_payment_provider,
                'qr_payment_account_name' => $setting->qr_payment_account_name,
                'qr_payment_phone' => $setting->qr_payment_phone,
                'qr_payment_instructions' => $setting->qr_payment_instructions,
                'qr_payment_qr_path' => $setting->qr_payment_qr_path,
                'qr_payment_qr_url' => $setting->qr_payment_qr_path ? Storage::disk('public')->url($setting->qr_payment_qr_path) : null,
                'qr_payment_enabled' => (bool) $setting->qr_payment_enabled,
                'revision' => (int) $setting->revision,
                'updated_at' => $setting->updated_at?->toIso8601String(),
                'updated_by' => $setting->updatedBy ? ['name' => $setting->updatedBy->name, 'email' => $setting->updatedBy->email] : null,
            ]),
        ];
    }

    public function qrPaymentInformation(): array
    {
        $settings = $this->current()['settings'];
        $configured = $settings['qr_payment_enabled'] === true
            && filled($settings['qr_payment_account_name'])
            && filled($settings['qr_payment_phone'])
            && filled($settings['qr_payment_qr_url']);

        if (! $configured) {
            return ['available' => false, 'reason' => 'not_configured'];
        }

        return [
            'available' => true,
            'reason' => null,
            'provider' => $settings['qr_payment_provider'],
            'account_name' => $settings['qr_payment_account_name'],
            'phone' => $settings['qr_payment_phone'],
            'qr_code_url' => $settings['qr_payment_qr_url'],
            'instructions' => $settings['qr_payment_instructions'],
            'scope' => 'marketplace',
        ];
    }

    public function uploadQrPaymentImage(UploadedFile $file, User $actor, MarketplaceAuditLogger $audit): array
    {
        if (! $this->storageAvailable()) {
            throw new ServiceUnavailableHttpException(null, 'Marketplace settings storage is not available. Apply the reviewed marketplace settings migration before saving settings.');
        }

        $path = $file->storeAs('marketplace/payment', Str::uuid().'.'.$file->extension(), 'public');
        $setting = MarketplaceSetting::query()->first();
        $oldPath = $setting?->qr_payment_qr_path;
        if (! $setting) {
            $setting = MarketplaceSetting::create(array_merge($this->defaults(), [
                'qr_payment_qr_path' => $path,
                'updated_by' => $actor->id,
            ]));
        } else {
            $setting->forceFill(['qr_payment_qr_path' => $path, 'revision' => (int) $setting->revision + 1, 'updated_by' => $actor->id])->save();
        }
        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }
        $audit->record('marketplace.global_qr_payment_image_updated', $actor, entity: $setting, metadata: ['changed_fields' => ['qr_payment_qr_path']]);

        return $this->current()['settings'];
    }

    public function removeQrPaymentImage(User $actor, MarketplaceAuditLogger $audit): array
    {
        $setting = MarketplaceSetting::query()->first();
        if (! $setting) {
            return $this->current()['settings'];
        }
        $oldPath = $setting->qr_payment_qr_path;
        $setting->forceFill(['qr_payment_qr_path' => null, 'revision' => (int) $setting->revision + 1, 'updated_by' => $actor->id])->save();
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }
        $audit->record('marketplace.global_qr_payment_image_removed', $actor, entity: $setting, metadata: ['changed_fields' => ['qr_payment_qr_path']]);

        return $this->current()['settings'];
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
            if ($setting) {
                $attributes = array_merge($data, ['updated_by' => $actor->id, 'revision' => (int) $setting->revision + 1]);
                if (! Schema::hasColumn('marketplace_settings', 'payout_schedule')) {
                    unset($attributes['payout_schedule'], $attributes['payout_minimum']);
                }
                $setting->forceFill($attributes)->save();
            } else {
                $attributes = array_merge($this->defaults(), $data, ['updated_by' => $actor->id]);
                if (! Schema::hasColumn('marketplace_settings', 'payout_schedule')) {
                    unset($attributes['payout_schedule'], $attributes['payout_minimum']);
                }
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
