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
        $stores = $context->tenant()->stores()->get()->filter(fn (Store $store) => $access->canAccessStore($request->user(), $store));
        if ($request->filled('store_id')) {
            $stores = $stores->where('uuid', $request->query('store_id'));
        }
        if ($stores->isEmpty()) {
            throw new NotFoundHttpException('The selected branch was not found.');
        }

        $members = StoreMembership::query()
            ->with('user')
            ->where('tenant_id', $context->tenant()->id)
            ->whereIn('store_id', $stores->pluck('id'))
            ->whereIn('role', ['salesperson', 'sales_staff', 'cashier'])
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->orderBy('role')
            ->orderBy('id')
            ->get()->unique('user_id')->values();

        return response()->json(['success' => true, 'data' => $members->map(fn (StoreMembership $membership): array => [
            'id' => $membership->user->id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'role' => $membership->role,
        ])->values()]);
    }
}
