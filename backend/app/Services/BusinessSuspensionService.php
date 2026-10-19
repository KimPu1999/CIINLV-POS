<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BusinessSuspensionService
{
    public function suspend(Tenant $tenant, User $actor, AuditLogger $auditLogger): Tenant
    {
        abort_if($tenant->status === 'archived', 409, 'Restore the archived business before suspending it.');
        return DB::transaction(function () use ($tenant, $actor, $auditLogger): Tenant {
            $tenant->forceFill([
                'status' => 'suspended',
                'suspended_at' => now(),
            ])->save();
            $auditLogger->record('platform.business_suspended', $actor, subject: $tenant);

            return $tenant->refresh();
        });
    }

    public function activate(Tenant $tenant, User $actor, AuditLogger $auditLogger): Tenant
    {
        abort_if($tenant->status === 'archived', 409, 'Restore the archived business before activating it.');
        return DB::transaction(function () use ($tenant, $actor, $auditLogger): Tenant {
            $tenant->forceFill([
                'status' => 'active',
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();
            $auditLogger->record('platform.business_activated', $actor, subject: $tenant);

            return $tenant->refresh();
        });
    }
}
