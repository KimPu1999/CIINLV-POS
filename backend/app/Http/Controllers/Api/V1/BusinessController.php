<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

class BusinessController
{
    public function profile(TenantContext $context): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new TenantResource($context->tenant())]);
    }

    public function dashboard(TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();

        return response()->json(['success' => true, 'data' => [
            'tenant' => new TenantResource($tenant),
            'stores' => $tenant->stores()->where('status', 'active')->count(),
            'members' => $tenant->memberships()->where('status', 'active')->count(),
        ]]);
    }

    public function members(TenantContext $context): JsonResponse
    {
        $members = $context->tenant()->memberships()->with(['user', 'role'])->where('status', 'active')->get();

        return response()->json(['success' => true, 'data' => $members->map(fn ($membership): array => [
            'user' => new UserResource($membership->user),
            'role' => $membership->role?->name,
            'status' => $membership->status,
        ])]);
    }
}
