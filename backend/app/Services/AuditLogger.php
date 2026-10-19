<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;

class AuditLogger
{
    public function record(
        string $action,
        ?User $actor = null,
        ?Tenant $tenant = null,
        ?object $subject = null,
        array $metadata = [],
    ): void {
        $tenant ??= $subject instanceof Tenant ? $subject : null;

        AuditLog::create([
            'actor_user_id' => $actor?->getKey(),
            'tenant_id' => $tenant?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
            'metadata' => $metadata,
        ]);
    }
}
