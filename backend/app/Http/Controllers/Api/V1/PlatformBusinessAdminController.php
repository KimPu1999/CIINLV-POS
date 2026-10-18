<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Platform\CreateBusinessAdminRequest;
use App\Http\Requests\Platform\CreateInvitedBusinessAdminRequest;
use App\Http\Requests\Platform\UpdateBusinessAdminRequest;
use App\Http\Resources\BusinessMembershipResource;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BusinessMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PlatformBusinessAdminController
{
    public function businessIndex(Tenant $business): JsonResponse
    {
        $members = $business->memberships()->with(['user', 'role'])
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->latest()->get();

        return response()->json(['success' => true, 'data' => BusinessMembershipResource::collection($members)]);
    }

    public function businessStore(CreateInvitedBusinessAdminRequest $request, Tenant $business, BusinessMembershipService $membershipService): JsonResponse
    {
        [$user, $membership, $invitation, $invitationStatus] = $membershipService->inviteBusinessAdmin(
            $business,
            $request->validated('name'),
            $request->validated('email'),
            $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Business administrator invitation created successfully',
            'data' => [
                'user' => new UserResource($user),
                'membership' => new BusinessMembershipResource($membership->load(['user', 'role'])),
                'invitation_status' => $invitationStatus,
            ],
        ], 201);
    }

    public function businessUpdate(UpdateBusinessAdminRequest $request, Tenant $business, User $user, AuditLogger $auditLogger): JsonResponse
    {
        $membership = TenantMembership::query()->where('tenant_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->firstOrFail();
        $data = $request->validated();
        $user->update($request->safe()->only(['name'])->toArray());

        if (array_key_exists('status', $data)) {
            $status = $data['status'];
            $membership->update(['status' => $status === 'active' ? 'active' : 'revoked']);
            $user->update(['status' => $status]);
            if ($status !== 'active') {
                $user->tokens()->delete();
            }
        }
        $auditLogger->record('platform.business_admin_updated', $request->user(), $business, $user);

        return response()->json(['success' => true, 'data' => new UserResource($user->refresh())]);
    }

    public function businessDestroy(Tenant $business, User $user, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $membership = TenantMembership::query()->where('tenant_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->firstOrFail();
        $membership->update(['status' => 'revoked']);
        $user->tokens()->delete();
        $auditLogger->record('platform.business_admin_revoked', $request->user(), $business, $user);

        return response()->json(['success' => true, 'message' => 'Business administrator access revoked.']);
    }

    public function index(Tenant $tenant): JsonResponse
    {
        $members = $tenant->memberships()->with(['user', 'role'])
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $members->map(fn (TenantMembership $membership): array => [
                'membership_id' => $membership->id,
                'user' => new UserResource($membership->user),
                'role' => $membership->role?->name,
                'status' => $membership->status,
            ]),
        ]);
    }

    public function store(CreateBusinessAdminRequest $request, Tenant $tenant, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validated();

        [$user, $membership, $invitationToken] = DB::transaction(function () use ($data, $tenant): array {
            $role = Role::firstOrCreate(['tenant_id' => $tenant->getKey(), 'name' => 'business_admin']);
            $hasPassword = filled($data['password'] ?? null);
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => Hash::make($hasPassword ? $data['password'] : Str::random(64)),
                'status' => $hasPassword ? User::STATUS_ACTIVE : User::STATUS_INVITED,
                'email_verified_at' => $hasPassword ? now() : null,
            ]);
            $membership = TenantMembership::create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $user->getKey(),
                'role_id' => $role->getKey(),
                'status' => 'active',
                'joined_at' => now(),
            ]);
            $invitationToken = $hasPassword ? null : Password::broker()->createToken($user);

            return [$user, $membership, $invitationToken];
        });

        $auditLogger->record('platform.business_admin_created', $request->user(), $tenant, $user);
        $response = [
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'membership_id' => $membership->id,
                'invitation_sent' => false,
            ],
        ];

        if ($invitationToken && app()->environment(['local', 'testing'])) {
            $response['data']['development_invitation_token'] = $invitationToken;
        }

        return response()->json($response, 201);
    }

    public function update(UpdateBusinessAdminRequest $request, Tenant $tenant, User $user, AuditLogger $auditLogger): JsonResponse
    {
        $membership = TenantMembership::query()->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->firstOrFail();

        $user->update($request->validated(['name']));
        if ($request->has('status')) {
            $status = $request->validated('status');
            $membership->update(['status' => $status === 'active' ? 'active' : 'revoked']);
            $user->update(['status' => $status]);
        }
        $auditLogger->record('platform.business_admin_updated', $request->user(), $tenant, $user);

        return response()->json(['success' => true, 'data' => new UserResource($user->refresh())]);
    }

    public function destroy(Tenant $tenant, User $user, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $membership = TenantMembership::query()->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->firstOrFail();
        $membership->update(['status' => 'revoked']);
        $user->tokens()->delete();
        $auditLogger->record('platform.business_admin_revoked', $request->user(), $tenant, $user);

        return response()->json(['success' => true, 'message' => 'Business administrator access revoked.']);
    }
}
