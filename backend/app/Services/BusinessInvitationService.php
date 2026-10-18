<?php

namespace App\Services;

use App\Jobs\SendBusinessInvitation;
use App\Models\BusinessInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessInvitationService
{
    public function createPending(Tenant $tenant, string $email, string $role, User $inviter): array
    {
        BusinessInvitation::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('email', strtolower($email))
            ->where('role', $role)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $plainToken = Str::random(64);
        $invitation = BusinessInvitation::create([
            'tenant_id' => $tenant->getKey(),
            'email' => strtolower($email),
            'role' => $role,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays((int) config('business.invitation_expiry_days', 7)),
            'invited_by' => $inviter->getKey(),
        ]);

        SendBusinessInvitation::dispatch($invitation->getKey(), Crypt::encryptString($plainToken))->afterCommit();

        return [$invitation, $plainToken];
    }

    public function accept(string $email, string $plainToken, string $password, AuditLogger $auditLogger): array
    {
        $invitation = BusinessInvitation::query()
            ->with('tenant')
            ->where('email', strtolower($email))
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $invitation || ! $invitation->isPending() || $invitation->tenant->status !== 'active') {
            throw ValidationException::withMessages(['token' => ['This invitation is invalid, expired, revoked, or no longer active.']]);
        }

        $user = User::query()->where('email', strtolower($email))->first();
        if (! $user) {
            throw ValidationException::withMessages(['email' => ['The invited account could not be found.']]);
        }

        $membership = $user->memberships()
            ->where('tenant_id', $invitation->tenant_id)
            ->whereHas('role', fn ($query) => $query->where('name', $invitation->role))
            ->first();

        if (! $membership) {
            throw ValidationException::withMessages(['token' => ['The invitation membership is no longer valid.']]);
        }

        $user->forceFill([
            'password' => Hash::make($password),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => $user->email_verified_at ?: now(),
        ])->save();
        $membership->forceFill(['status' => 'active', 'joined_at' => now()])->save();
        $invitation->forceFill(['accepted_at' => now()])->save();
        $auditLogger->record('business.invitation_accepted', $user, $invitation->tenant, $user);

        return [$user->refresh(), $invitation->tenant->refresh()];
    }
}
