<?php

namespace App\Services;

use App\Jobs\SendBusinessInvitation;
use App\Models\BusinessInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class BusinessInvitationService
{
    public function createPending(Tenant $tenant, string $email, string $role, User $inviter): array
    {
        abort_if($tenant->status === 'archived', 409, 'Archived businesses cannot receive invitations.');
        BusinessInvitation::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('email', strtolower($email))
            ->where('role', $role)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        // Keep the plaintext only long enough to encrypt it for delivery.
        // PostgreSQL stores only the SHA-256 digest.
        $plainToken = bin2hex(random_bytes(32));
        $invitation = BusinessInvitation::create([
            'tenant_id' => $tenant->getKey(),
            'email' => strtolower($email),
            'role' => $role,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHours(max(1, (int) config('business.invitation_expiry_hours', 24))),
            'invited_by' => $inviter->getKey(),
        ]);

        // Keep the delivery secret out of database queue payloads. PostgreSQL
        // stores only the SHA-256 digest; the encrypted secret lives in the
        // configured short-lived cache until the notification is delivered.
        Cache::put($invitation->deliverySecretCacheKey(), Crypt::encryptString($plainToken), $invitation->expires_at);
        SendBusinessInvitation::dispatch($invitation->getKey())->afterCommit();

        return [$invitation, $plainToken];
    }

    public function details(string $plainToken): BusinessInvitation
    {
        $invitation = BusinessInvitation::query()
            ->with(['tenant', 'user'])
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $invitation || ! $invitation->isPending() || in_array($invitation->tenant?->status, ['suspended', 'archived'], true)) {
            throw ValidationException::withMessages(['token' => ['This invitation is invalid, expired, revoked, or no longer active.']]);
        }

        return $invitation;
    }

    public function accept(string $email, string $plainToken, ?string $password, ?User $authenticatedUser, AuditLogger $auditLogger): array
    {
        return DB::transaction(function () use ($email, $plainToken, $password, $authenticatedUser, $auditLogger): array {
            $invitation = BusinessInvitation::query()
                ->with('tenant')
                ->where('email', strtolower($email))
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! $invitation->isPending() || in_array($invitation->tenant->status, ['suspended', 'archived'], true)) {
                throw ValidationException::withMessages(['token' => ['This invitation is invalid, expired, revoked, or no longer active.']]);
            }

            $user = User::query()->where('email', strtolower($email))->lockForUpdate()->first();
            if (! $user) {
                throw ValidationException::withMessages(['email' => ['The invited account could not be found.']]);
            }

            if ($user->isSuperAdmin()) {
                throw ValidationException::withMessages(['token' => ['A platform administrator cannot activate a business administrator invitation.']]);
            }

            $membership = $user->memberships()
                ->where('tenant_id', $invitation->tenant_id)
                ->whereHas('role', fn ($query) => $query->where('name', $invitation->role))
                ->lockForUpdate()
                ->first();

            if (! $membership) {
                throw ValidationException::withMessages(['token' => ['The invitation membership is no longer valid.']]);
            }

            if ($user->isActive()) {
                if (! $authenticatedUser || $authenticatedUser->getKey() !== $user->getKey()) {
                    throw new AuthorizationException('Sign in to the existing account before accepting this invitation.');
                }
            } else {
                if (! filled($password)) {
                    throw ValidationException::withMessages(['password' => ['A strong password is required to activate this account.']]);
                }

                $user->forceFill([
                    'password' => Hash::make($password),
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => $user->email_verified_at ?: now(),
                ])->save();
            }

            $membership->forceFill(['status' => 'active', 'joined_at' => now()])->save();
            $invitation->forceFill(['accepted_at' => now()])->save();
            $invitation->tenant->forceFill([
                'email_verified_at' => now(),
                'email_verification_status' => 'verified',
                'status' => config('business.require_super_admin_approval') ? 'pending' : 'active',
                'approval_status' => config('business.require_super_admin_approval') ? 'pending' : 'approved',
                'approved_at' => config('business.require_super_admin_approval') ? null : now(),
            ])->save();
            $auditLogger->record('business.invitation_accepted', $user, $invitation->tenant, $user);

            return [$user->refresh(), $invitation->tenant->refresh()];
        });
    }
}
