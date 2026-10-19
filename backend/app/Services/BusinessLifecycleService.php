<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BusinessLifecycleService
{
    public function archive(Tenant $business, User $actor): Tenant
    {
        return DB::transaction(function () use ($business, $actor): Tenant {
            $locked = Tenant::query()->whereKey($business->getKey())->lockForUpdate()->firstOrFail();
            abort_if($locked->status === 'archived', 409, 'Business is already archived.');
            $locked->forceFill([
                'status' => 'archived',
                'archived_at' => now(),
                'archived_by' => $actor->getKey(),
                'archived_previous_status' => $locked->status,
            ])->save();
            app(AuditLogger::class)->record('platform.business_archived', $actor, subject: $locked);

            return $locked->refresh();
        });
    }

    public function restore(Tenant $business, User $actor): Tenant
    {
        return DB::transaction(function () use ($business, $actor): Tenant {
            $locked = Tenant::query()->whereKey($business->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'archived', 409, 'Only archived businesses can be restored.');
            $locked->forceFill([
                'status' => in_array($locked->archived_previous_status, ['pending', 'active', 'suspended'], true) ? $locked->archived_previous_status : 'active',
                'archived_at' => null,
                'archived_by' => null,
                'archived_previous_status' => null,
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();
            app(AuditLogger::class)->record('platform.business_restored', $actor, subject: $locked);

            return $locked->refresh();
        });
    }
}
