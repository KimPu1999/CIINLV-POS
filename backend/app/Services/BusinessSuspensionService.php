<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BusinessSuspensionService
{
    public function suspend(Tenant $tenant, User $actor, AuditLogger $auditLogger, ?string $reason = null): Tenant
    {
        abort_if($tenant->status === 'archived', 409, 'Restore the archived business before suspending it.');

        return DB::transaction(function () use ($tenant, $actor, $auditLogger, $reason): Tenant {
            $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            abort_if($locked->status === 'archived', 409, 'Restore the archived business before suspending it.');
            $locked->forceFill([
                'status' => 'suspended',
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ])->save();
            $auditLogger->record('platform.business_suspended', $actor, subject: $locked, metadata: ['reason' => $reason]);

            return $locked->refresh();
        });
    }

    public function activate(Tenant $tenant, User $actor, AuditLogger $auditLogger): Tenant
    {
        abort_if($tenant->status === 'archived', 409, 'Restore the archived business before activating it.');

        return DB::transaction(function () use ($tenant, $actor, $auditLogger): Tenant {
            $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            abort_if($locked->status === 'archived', 409, 'Restore the archived business before activating it.');
            $locked->forceFill([
                'status' => 'active',
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();
            $auditLogger->record('platform.business_activated', $actor, subject: $locked);

            return $locked->refresh();
        });
    }
}
