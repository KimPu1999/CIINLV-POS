<?php

namespace App\Services;

use App\Models\BusinessIdempotencyKey;
use App\Models\BusinessSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessProvisioningService
{
    public function __construct(
        private readonly BusinessMembershipService $membershipService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function provision(User $actor, array $data, ?string $idempotencyKey = null): array
    {
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($actor, $data, $idempotencyKey, $requestHash): array {
                $existingKey = $idempotencyKey
                    ? BusinessIdempotencyKey::query()->where('actor_user_id', $actor->getKey())->where('idempotency_key', $idempotencyKey)->first()
                    : null;

                if ($existingKey) {
                    if ($existingKey->request_hash !== $requestHash) {
                        throw ValidationException::withMessages(['Idempotency-Key' => ['This key was already used with a different request.']]);
                    }

                    return $this->hydrateResult(Tenant::query()->findOrFail($existingKey->tenant_id));
                }

                $businessCode = Str::upper(trim($data['business_code']));
                if (Tenant::query()->where('business_code', $businessCode)->exists()) {
                    throw ValidationException::withMessages(['business_code' => ['This business code is already in use.']]);
                }

                $tenant = Tenant::create([
                    'name' => $data['business_name'],
                    'slug' => Str::slug($businessCode),
                    'business_code' => $businessCode,
                    'email' => $data['business_email'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'address' => $data['address'] ?? null,
                    'status' => 'active',
                    'subscription_status' => 'trial',
                    'created_by' => $actor->getKey(),
                ]);
                BusinessSetting::create([
                    'tenant_id' => $tenant->getKey(),
                    'currency_code' => $data['currency_code'] ?? 'MMK',
                    'timezone' => $data['timezone'] ?? 'Asia/Yangon',
                    'locale' => $data['locale'] ?? 'en',
                    'tax_configuration' => $data['tax_configuration'] ?? [],
                    'receipt_configuration' => $data['receipt_configuration'] ?? [],
                ]);

                [$admin, $membership, $invitation, $invitationStatus] = $this->membershipService->inviteBusinessAdmin(
                    $tenant,
                    $data['admin_name'],
                    $data['admin_email'],
                    $actor,
                );
                $this->auditLogger->record('platform.business_created', $actor, subject: $tenant, metadata: [
                    'business_code' => $businessCode,
                    'administrator_user_id' => $admin->getKey(),
                ]);

                if ($idempotencyKey) {
                    BusinessIdempotencyKey::create([
                        'actor_user_id' => $actor->getKey(),
                        'idempotency_key' => $idempotencyKey,
                        'request_hash' => $requestHash,
                        'tenant_id' => $tenant->getKey(),
                        'response_status' => 201,
                        'response_payload' => ['tenant_id' => $tenant->getKey(), 'user_id' => $admin->getKey(), 'invitation_status' => $invitationStatus],
                    ]);
                }

                return [
                    'tenant' => $tenant->load('businessSetting'),
                    'admin' => $admin,
                    'membership' => $membership->load('role'),
                    'invitation' => $invitation,
                    'invitation_status' => $invitationStatus,
                ];
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'business_code')) {
                throw ValidationException::withMessages(['business_code' => ['This business code is already in use.']]);
            }

            throw $exception;
        }
    }

    private function hydrateResult(Tenant $tenant): array
    {
        $membership = $tenant->memberships()->with(['user', 'role'])->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))->latest()->firstOrFail();

        return [
            'tenant' => $tenant->load('businessSetting'),
            'admin' => $membership->user,
            'membership' => $membership,
            'invitation' => $tenant->invitations()->where('email', $membership->user->email)->whereNull('accepted_at')->latest()->first(),
            'invitation_status' => $membership->user->isActive() ? 'not_required' : 'pending',
        ];
    }
}
