<?php

namespace App\Services;

use App\Models\MarketplaceAuditLog;
use App\Models\Tenant;
use App\Models\User;

class MarketplaceAuditLogger
{
    public function record(
        string $action,
        ?User $actor = null,
        ?Tenant $tenant = null,
        ?object $entity = null,
        array $metadata = [],
    ): void {
        MarketplaceAuditLog::create([
            'actor_user_id' => $actor?->getKey(),
            'tenant_id' => $tenant?->getKey(),
            'action' => $action,
            'entity_type' => $entity ? $entity::class : null,
            'entity_id' => $entity?->getKey() === null ? null : (string) $entity->getKey(),
            'metadata' => $metadata,
        ]);
    }
}
