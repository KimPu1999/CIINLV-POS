<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessMembershipService
{
    public function inviteBusinessAdmin(Tenant $tenant, string $name, string $email, User $inviter): array
    {
        $email = strtolower(trim($email));
        $role = Role::firstOrCreate(['tenant_id' => $tenant->getKey(), 'name' => 'business_admin']);
        $user = User::query()->where('email', $email)->first();

        if ($user && $user->memberships()->where('tenant_id', $tenant->getKey())->exists()) {
            throw ValidationException::withMessages(['admin_email' => ['This user already has a membership in the business.']]);
        }

        if ($user?->isSuperAdmin()) {
            throw ValidationException::withMessages(['admin_email' => ['A platform administrator cannot be invited as a business administrator.']]);
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

        $membership = TenantMembership::create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role_id' => $role->getKey(),
            'status' => $user->isActive() ? 'active' : 'pending',
            'invited_by' => $inviter->getKey(),
            'joined_at' => $user->isActive() ? now() : null,
        ]);

        if ($user->isActive()) {
            return [$user, $membership, null, 'not_required'];
        }

        [$invitation] = app(BusinessInvitationService::class)->createPending($tenant, $email, 'business_admin', $inviter);

        return [$user, $membership, $invitation, 'pending'];
    }
}
