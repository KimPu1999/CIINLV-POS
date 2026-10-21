<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\App;

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
        if (App::bound('request')) {
            $correlationId = request()->attributes->get('correlation_id');
            if (is_string($correlationId) && $correlationId !== '') {
                $metadata['correlation_id'] ??= $correlationId;
            }
        }

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
