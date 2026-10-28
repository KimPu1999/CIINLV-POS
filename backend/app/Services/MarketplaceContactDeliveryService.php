<?php

namespace App\Services;

use App\Models\MarketplaceContactInquiry;
use App\Models\MarketplaceStore;
use App\Models\Tenant;

class MarketplaceContactDeliveryService
{
    public function mailConfigured(): bool
    {
        $mailer = strtolower((string) config('mail.default'));
        $from = trim((string) config('mail.from.address'));

        return ! in_array($mailer, ['', 'log', 'array'], true)
            && $from !== ''
            && ! str_ends_with(strtolower($from), '@example.com');
    }

    public function supportEmail(MarketplaceContactInquiry $inquiry): ?string
    {
        $inquiry->loadMissing(['store.tenant.businessSetting', 'tenant.businessSetting']);
        $store = $inquiry->store;
        $tenant = $inquiry->tenant ?: $store?->tenant;
        $configuration = $tenant?->businessSetting?->notification_configuration ?? [];

        $candidates = [
            is_array($configuration) ? ($configuration['support_email'] ?? null) : null,
            $store?->support_contact,
            $store?->public_contact_email,
            $tenant?->email,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && filter_var(trim($candidate), FILTER_VALIDATE_EMAIL)) {
                return trim($candidate);
            }
        }

        return null;
    }

    public function storeSupportEmail(?MarketplaceStore $store, ?Tenant $tenant): ?string
    {
        $configuration = $tenant?->businessSetting?->notification_configuration ?? [];
        $candidates = [
            is_array($configuration) ? ($configuration['support_email'] ?? null) : null,
            $store?->support_contact,
            $store?->public_contact_email,
            $tenant?->email,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && filter_var(trim($candidate), FILTER_VALIDATE_EMAIL)) {
                return trim($candidate);
            }
        }

        return null;
    }
}
