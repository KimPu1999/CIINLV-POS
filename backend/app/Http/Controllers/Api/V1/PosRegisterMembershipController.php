<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pos\AssignRegisterCashierRequest;
use App\Http\Resources\PosRegisterMembershipResource;
use App\Models\PosRegister;
use App\Models\PosRegisterMembership;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PosRegisterMembershipController
{
    public function index(PosRegister $register, TenantContext $context, Request $request): JsonResponse
    {
        $this->assertTenant($register, $context);
        Gate::forUser($request->user())->authorize('view', $register);

        return response()->json(['success' => true, 'data' => PosRegisterMembershipResource::collection($register->memberships()->with(['register', 'store', 'user'])->where('status', 'active')->get())]);
    }

    public function store(AssignRegisterCashierRequest $request, PosRegister $register, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($register, $context);
        Gate::forUser($request->user())->authorize('manage', $register);
        $user = $context->tenant()->users()->whereKey($request->integer('user_id'))->first();
        $role = $user?->memberships()->where('tenant_id', $context->tenant()->id)->where('status', 'active')->with('role')->first()?->role?->name;
        $storeAssignment = $user?->storeMemberships()->where('tenant_id', $context->tenant()->id)->where('store_id', $register->store_id)->where('role', 'cashier')->where('status', 'active')->whereNull('revoked_at')->exists();
        if (! $user || ! $user->isActive() || $role !== 'cashier' || ! $storeAssignment) {
            throw new UnprocessableEntityHttpException('The user must be an active cashier assigned to this store in the same business.');
        }
        $membership = PosRegisterMembership::where('register_id', $register->id)->where('user_id', $user->id)->first();
        if ($membership?->status === 'active') {
            throw new ConflictHttpException('This cashier is already assigned to the register.');
        }
        $membership ??= new PosRegisterMembership;
        $membership->fill(['tenant_id' => $context->tenant()->id, 'store_id' => $register->store_id, 'register_id' => $register->id, 'user_id' => $user->id, 'status' => 'active', 'assigned_by' => $request->user()->id, 'assigned_at' => now(), 'revoked_at' => null]);
        $membership->save();
        $audit->record('pos.register_cashier_assigned', $request->user(), $context->tenant(), $membership, ['register_id' => $register->uuid, 'user_id' => $user->id]);

        return response()->json(['success' => true, 'data' => new PosRegisterMembershipResource($membership->load(['register', 'store', 'user']))], 201);
    }

    public function destroy(PosRegister $register, PosRegisterMembership $membership, TenantContext $context, Request $request, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($register, $context);
        if ((int) $membership->register_id !== (int) $register->id || (int) $membership->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested register assignment was not found.');
        }
        Gate::forUser($request->user())->authorize('manage', $register);
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        $audit->record('pos.register_cashier_revoked', $request->user(), $context->tenant(), $membership, ['register_id' => $register->uuid, 'user_id' => $membership->user_id]);

        return response()->json(['success' => true, 'message' => 'Cashier register access revoked.']);
    }

    private function assertTenant(PosRegister $register, TenantContext $context): void
    {
        if ((int) $register->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested register was not found.');
        }
    }
}
