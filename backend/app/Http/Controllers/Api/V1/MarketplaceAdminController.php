<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\MarketplaceApprovalRequest;
use App\Http\Requests\Marketplace\CollectMarketplaceCodPaymentRequest;
use App\Http\Resources\MarketplaceOrderResource;
use App\Http\Resources\MarketplaceStoreResource;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceStore;
use App\Services\MarketplaceAuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketplaceAdminController
{
    public function stores(Request $request): JsonResponse
    {
        $stores = MarketplaceStore::query()
            ->with('tenant')
            ->withCount('listings')
            ->when($request->filled('approval_status'), fn (Builder $query) => $query->where('approval_status', $request->string('approval_status')))
            ->when($request->filled('search'), fn (Builder $query) => $query->where(function (Builder $search) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $search->where('marketplace_stores.name', 'ilike', $term)->orWhereHas('tenant', fn (Builder $tenant) => $tenant->where('tenants.name', 'ilike', $term));
            }))
            ->orderByDesc('marketplace_stores.created_at')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json([
            'success' => true,
            'data' => MarketplaceStoreResource::collection($stores),
            'meta' => ['total' => $stores->total(), 'per_page' => $stores->perPage(), 'current_page' => $stores->currentPage(), 'last_page' => $stores->lastPage()],
        ]);
    }

    public function approval(MarketplaceApprovalRequest $request, MarketplaceStore $store, MarketplaceAuditLogger $audit): JsonResponse
    {
        $status = $request->string('approval_status')->toString();
        $store->load('tenant');
        if ($status === 'approved' && $store->tenant?->status !== 'active') {
            throw ValidationException::withMessages(['approval_status' => ['Only active businesses can be approved for the marketplace.']]);
        }

        $store->forceFill([
            'approval_status' => $status,
            'approved_at' => $status === 'approved' ? now() : null,
            'approved_by' => $status === 'approved' ? $request->user()->id : null,
        ])->save();
        $audit->record('marketplace.store_approval_updated', $request->user(), $store->tenant, $store, ['approval_status' => $status]);

        return response()->json(['success' => true, 'data' => new MarketplaceStoreResource($store->refresh()->load('tenant'))]);
    }

    public function collectCod(CollectMarketplaceCodPaymentRequest $request, string $order, MarketplaceAuditLogger $audit): JsonResponse
    {
        $model = DB::transaction(function () use ($request, $order): MarketplaceOrder {
            $model = MarketplaceOrder::where('uuid', $order)->lockForUpdate()->firstOrFail();
            $payment = $model->payments()->where('method', 'cod')->lockForUpdate()->firstOrFail();
            if ($payment->status === 'pending') {
                $payment->update(['status' => 'paid', 'paid_at' => now(), 'metadata' => array_merge((array) $payment->metadata, ['collected_by' => $request->user()->uuid, 'verification_note' => $request->input('verification_note')])]);
                $model->update(['payment_status' => 'paid']);
                $model->events()->create(['actor_user_id' => $request->user()->id, 'event' => 'cod_payment_collected', 'from_status' => 'pending', 'to_status' => 'paid', 'metadata' => ['verification_note' => $request->input('verification_note')]]);
            }

            return $model;
        });
        $audit->record('marketplace.cod_payment_collected', $request->user(), entity: $model, metadata: ['order_number' => $model->order_number]);

        return response()->json(['success' => true, 'data' => new MarketplaceOrderResource($model->load(['payments', 'vendorOrders.order', 'vendorOrders.store', 'vendorOrders.items']))]);
    }
}
