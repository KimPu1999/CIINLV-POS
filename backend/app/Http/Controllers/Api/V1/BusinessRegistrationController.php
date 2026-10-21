<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\RegisterBusinessRequest;
use App\Jobs\SendBusinessEmailVerification;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class BusinessRegistrationController
{
    public function register(RegisterBusinessRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validated();
        try {
            [$tenant, $owner] = DB::transaction(function () use ($data, $auditLogger): array {
                $tenant = Tenant::create([
                    'name' => $data['business_name'], 'slug' => Str::slug($data['business_code']),
                    'business_code' => $data['business_code'], 'email' => $data['business_email'] ?? $data['owner_email'],
                    'phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null, 'country' => $data['country'] ?? null,
                    'business_type' => $data['business_type'] ?? null, 'id_type' => $data['id_type'] ?? null, 'id_number' => $data['id_number'] ?? null,
                    'company_registration_number' => $data['company_registration_number'] ?? null, 'personal_registration_number' => $data['personal_registration_number'] ?? null,
                    'status' => 'pending', 'approval_status' => config('business.require_super_admin_approval') ? 'pending' : 'approved',
                    'email_verification_status' => 'pending_email_verification',
                    'subscription_status' => 'trial',
                ]);
                $owner = User::create([
                    'name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => Hash::make($data['password']),
                    // A public registrant cannot authenticate until the signed
                    // owner-email verification activates this account.
                    'status' => User::STATUS_INVITED,
                ]);
                $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
                TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role_id' => $role->id, 'status' => 'pending']);
                $auditLogger->record('business.registration_submitted', tenant: $tenant, subject: $tenant, metadata: [
                    'source' => 'public_registration',
                    'business_code' => $tenant->business_code,
                ]);

                return [$tenant, $owner];
            });
        } catch (UniqueConstraintViolationException $exception) {
            Log::notice('Business registration rejected by a database uniqueness constraint.', [
                'constraint' => $exception->getMessage(),
                'business_code' => $data['business_code'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Some registration details are already in use.',
                'errors' => $this->uniqueConstraintErrors($exception),
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Business registration database transaction failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'We could not save the registration right now. Please try again shortly.'], 500);
        }

        $this->storeRegistrationFiles($tenant, $owner, $data);

        $emailDelivery = 'queued';
        try {
            SendBusinessEmailVerification::dispatch($owner->id, $tenant->id)->afterCommit();
        } catch (\Throwable $exception) {
            $emailDelivery = 'failed';
            Log::error('Business registration succeeded but verification email could not be queued.', [
                'user_id' => $owner->id,
                'tenant_id' => $tenant->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Registration received. Check your email to verify your address.', 'data' => ['business_name' => $tenant->name, 'email' => $owner->email, 'verification_required' => true, 'email_delivery' => $emailDelivery]], 201);
    }

    private function storeRegistrationFiles(Tenant $tenant, User $owner, array $data): void
    {
        $stored = [];
        try {
            foreach (['business_logo' => 'logo_path', 'id_front_image' => 'id_front_image_path', 'id_back_image' => 'id_back_image_path', 'company_certificate_image' => 'company_certificate_image_path'] as $input => $column) {
                if (! isset($data[$input])) {
                    continue;
                }
                $file = $data[$input];
                $path = $file->storeAs('businesses/'.$tenant->uuid.'/verification', Str::uuid().'.'.$file->extension(), 'private');
                $stored[] = $path;
                $tenant->{$column} = $path;
            }
            if (isset($data['admin_profile_image'])) {
                $path = $data['admin_profile_image']->storeAs('businesses/'.$tenant->uuid.'/verification', Str::uuid().'.'.$data['admin_profile_image']->extension(), 'private');
                $stored[] = $path;
                $owner->profile_image_path = $path;
                $owner->save();
            }
            if ($stored) {
                $tenant->verification_status = 'pending';
                $tenant->save();
            }
        } catch (\Throwable $exception) {
            foreach ($stored as $path) {
                Storage::disk('private')->delete($path);
            }
            throw $exception;
        }
    }

    public function verify(Request $request, int $id, string $hash, AuditLogger $auditLogger): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        if (! URL::hasValidSignature($request)) {
            return redirect()->to($frontend.'/email-verification/invalid?reason=expired');
        }
        $user = User::query()->find($id);
        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->to($frontend.'/email-verification/invalid?reason=invalid');
        }
        if ($user->hasVerifiedEmail()) {
            return redirect()->to($frontend.'/email-verification/success?already=1');
        }

        $tenantId = $request->integer('tenant_id') ?: null;
        $verifiedTenant = DB::transaction(function () use ($user, $tenantId): ?Tenant {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $lockedUser->forceFill([
                'email_verified_at' => now(),
                'status' => User::STATUS_ACTIVE,
            ])->save();
            $membershipQuery = $lockedUser->memberships()
                ->where('status', 'pending')
                ->whereHas('role', fn ($q) => $q->where('name', 'business_admin'))
                ->with('tenant');
            if ($tenantId) {
                $membershipQuery->where('tenant_id', $tenantId);
            }
            $memberships = $membershipQuery->get();
            // Legacy links did not contain tenant_id. Do not guess when an
            // account has multiple pending business memberships.
            if (! $tenantId && $memberships->count() !== 1) {
                return null;
            }
            $membership = $memberships->first();
            if (! $membership) {
                return null;
            }
            $tenant = $membership->tenant;
            if (! $tenant) {
                return null;
            }
            $membership->forceFill(['status' => 'active', 'joined_at' => now()])->save();
            $tenant->forceFill([
                'email_verified_at' => now(),
                'email_verification_status' => 'verified',
                'status' => config('business.require_super_admin_approval') ? 'pending' : 'active',
                'approval_status' => config('business.require_super_admin_approval') ? 'pending' : 'approved',
                'approved_at' => config('business.require_super_admin_approval') ? null : now(),
            ])->save();

            return $tenant;
        });
        if ($verifiedTenant) {
            $auditLogger->record('business.owner_email_verified', tenant: $verifiedTenant, subject: $verifiedTenant, metadata: [
                'source' => 'signed_email_link',
            ]);
        }

        return redirect()->to($frontend.'/email-verification/success');
    }

    public function resend(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['success' => true, 'message' => 'If the account exists, a verification email will be sent shortly.']);
        }
        $user = User::query()->where('email', $email)->first();
        if ($user && ! $user->hasVerifiedEmail()) {
            $tenant = $user->memberships()->where('status', 'pending')->with('tenant')->first()?->tenant;
            if ($tenant) {
                try {
                    SendBusinessEmailVerification::dispatch($user->id, $tenant->id)->afterCommit();
                } catch (\Throwable $exception) {
                    Log::error('Verification email resend failed.', [
                        'user_id' => $user->id,
                        'tenant_id' => $tenant->id,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return response()->json(['success' => true, 'message' => 'If the account exists, a verification email will be sent shortly.']);
    }

    public function approve(Tenant $business, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('manage', $business);
        $approved = DB::transaction(function () use ($business, $request, $auditLogger): Tenant {
            $locked = Tenant::query()->whereKey($business->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->email_verified_at) {
                abort(422, 'The business owner must verify the email address before approval.');
            }
            abort_if($locked->status === 'archived', 409, 'Restore the archived business before approval.');
            $locked->forceFill(['approval_status' => 'approved', 'verification_status' => 'approved', 'approved_at' => now(), 'approved_by' => $request->user()->id, 'status' => 'active'])->save();
            $auditLogger->record('business.registration_approved', $request->user(), $locked, $locked, ['source' => 'platform_approval']);

            return $locked->refresh();
        });

        return response()->json(['success' => true, 'message' => 'Business approved.', 'data' => $approved]);
    }

    private function uniqueConstraintErrors(UniqueConstraintViolationException $exception): array
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'users_email') || str_contains($message, 'users_email_unique')) {
            return ['owner_email' => ['This owner email is already registered. Use the existing account or choose another email.']];
        }
        if (str_contains($message, 'business_code')) {
            return ['business_code' => ['This business code is already registered. Choose another code.']];
        }
        if (str_contains($message, 'tenants_slug')) {
            return ['business_code' => ['This business code creates a duplicate business slug. Choose another code.']];
        }

        return ['registration' => ['Some registration details are already in use.']];
    }
}
