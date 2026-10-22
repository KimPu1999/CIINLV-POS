<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Store;
use App\Models\StoreMembership;
use App\Services\PosAccessService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PosSalespersonController
{
    public function index(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $store = Store::query()->where('tenant_id', $context->tenant()->id)->where('uuid', $request->query('store_id'))->first();
        if (! $store || ! $access->canAccessStore($request->user(), $store)) {
            throw new NotFoundHttpException('The selected branch was not found.');
        }

        $members = StoreMembership::query()
            ->with('user')
            ->where('tenant_id', $context->tenant()->id)
            ->where('store_id', $store->id)
            ->whereIn('role', ['salesperson', 'sales_staff', 'cashier'])
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->orderBy('role')
            ->orderBy('id')
            ->get();

        return response()->json(['success' => true, 'data' => $members->map(fn (StoreMembership $membership): array => [
            'id' => $membership->user->id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'role' => $membership->role,
        ])->values()]);
    }
}
