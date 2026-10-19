<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessMembershipService
{
    public function inviteBusinessAdmin(Tenant $tenant, string $name, string $email, User $inviter): array
    {
        return DB::transaction(function () use ($tenant, $name, $email, $inviter): array {
            $email = strtolower(trim($email));
            $role = Role::firstOrCreate(['tenant_id' => $tenant->getKey(), 'name' => 'business_admin']);
            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            if ($user?->isSuperAdmin()) {
                throw ValidationException::withMessages(['admin_email' => ['A platform administrator cannot be invited as a business administrator.']]);
            }

            if ($user && ! $user->isActive() && $user->status !== User::STATUS_INVITED) {
                throw ValidationException::withMessages(['admin_email' => ['This account is not eligible for invitation activation.']]);
            }

            $membership = $user?->memberships()->with('role')->where('tenant_id', $tenant->getKey())->lockForUpdate()->first();
            if ($membership && $membership->status !== 'revoked') {
                throw ValidationException::withMessages(['admin_email' => ['This user already has a pending or active membership in the business.']]);
            }

            if (! $user) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                    'status' => User::STATUS_INVITED,
                ]);
            } else {
                $user->forceFill(['name' => $name])->save();
            }

            if ($membership) {
                $membership->forceFill([
                    'role_id' => $role->getKey(),
                    'status' => 'pending',
                    'invited_by' => $inviter->getKey(),
                    'joined_at' => null,
                ])->save();
            } else {
                $membership = TenantMembership::create([
                    'tenant_id' => $tenant->getKey(),
                    'user_id' => $user->getKey(),
                    'role_id' => $role->getKey(),
                    'status' => 'pending',
                    'invited_by' => $inviter->getKey(),
                    'joined_at' => null,
                ]);
            }

            [$invitation, $plainToken] = app(BusinessInvitationService::class)->createPending($tenant, $email, 'business_admin', $inviter);

            return [$user, $membership, $invitation, 'pending', $plainToken];
        });
    }
}
