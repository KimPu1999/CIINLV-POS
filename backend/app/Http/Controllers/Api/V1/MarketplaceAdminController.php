<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\MarketplaceApprovalRequest;
use App\Http\Requests\Marketplace\CollectMarketplaceCodPaymentRequest;
use App\Http\Requests\Marketplace\MarketplaceRequestDecisionRequest;
use App\Http\Resources\MarketplaceCancellationRequestResource;
use App\Http\Resources\MarketplaceOrderResource;
use App\Http\Resources\MarketplaceRefundRequestResource;
use App\Http\Resources\MarketplaceReturnRequestResource;
use App\Http\Resources\MarketplaceShipmentResource;
use App\Http\Resources\MarketplaceStoreResource;
use App\Models\MarketplaceCancellationRequest;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRefundRequest;
use App\Models\MarketplaceReturnRequest;
use App\Models\MarketplaceShipment;
use App\Models\MarketplaceStore;
use App\Services\MarketplaceAuditLogger;
use App\Services\MarketplaceOrderRequestService;
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

        return response()->json(['success' => true, 'data' => new MarketplaceOrderResource($model->load(['payments', 'vendorOrders.order', 'vendorOrders.store', 'vendorOrders.items', 'vendorOrders.shipments.events']))]);
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = MarketplaceOrder::query()
            ->with(['payments', 'vendorOrders.order', 'vendorOrders.store', 'vendorOrders.items', 'vendorOrders.shipments.events'])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('payment_status'), fn (Builder $query) => $query->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('search'), fn (Builder $query) => $query->where('order_number', 'ilike', '%'.$request->string('search').'%'))
            ->when($request->filled('from'), fn (Builder $query) => $query->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereDate('created_at', '<=', $request->date('to')))
            ->latest()
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => MarketplaceOrderResource::collection($orders), 'meta' => ['total' => $orders->total(), 'per_page' => $orders->perPage(), 'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()]]);
    }

    public function shipments(Request $request): JsonResponse
    {
        $shipments = MarketplaceShipment::query()
            ->with(['vendorOrder.order', 'vendorOrder.store', 'events'])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), fn (Builder $query) => $query->where(function (Builder $search) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $search->where('internal_tracking_id', 'ilike', $term)->orWhere('tracking_number', 'ilike', $term)->orWhereHas('vendorOrder', fn (Builder $vendor) => $vendor->where('vendor_order_number', 'ilike', $term));
            }))
            ->latest()
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => MarketplaceShipmentResource::collection($shipments), 'meta' => ['total' => $shipments->total(), 'per_page' => $shipments->perPage(), 'current_page' => $shipments->currentPage(), 'last_page' => $shipments->lastPage()]]);
    }

    public function refundRequests(Request $request): JsonResponse
    {
        $refunds = MarketplaceRefundRequest::query()
            ->with('order')
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => MarketplaceRefundRequestResource::collection($refunds), 'meta' => ['total' => $refunds->total(), 'per_page' => $refunds->perPage(), 'current_page' => $refunds->currentPage(), 'last_page' => $refunds->lastPage()]]);
    }

    public function cancellationRequests(Request $request): JsonResponse
    {
        $requests = MarketplaceCancellationRequest::query()->with('order')->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))->latest()->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => MarketplaceCancellationRequestResource::collection($requests), 'meta' => ['total' => $requests->total(), 'per_page' => $requests->perPage(), 'current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage()]]);
    }

    public function decideCancellation(MarketplaceRequestDecisionRequest $request, string $cancellation, MarketplaceOrderRequestService $service, MarketplaceAuditLogger $audit): JsonResponse
    {
        $model = MarketplaceCancellationRequest::where('uuid', $cancellation)->firstOrFail();
        $decided = $service->decideCancellation($model, $request->user(), $request->string('decision')->toString(), $request->input('note'));
        $audit->record('marketplace.cancellation_request_decided', $request->user(), entity: $decided, metadata: ['decision' => $decided->status]);

        return response()->json(['success' => true, 'data' => new MarketplaceCancellationRequestResource($decided)]);
    }

    public function returnRequests(Request $request): JsonResponse
    {
        $requests = MarketplaceReturnRequest::query()->with('order')->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))->latest()->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => MarketplaceReturnRequestResource::collection($requests), 'meta' => ['total' => $requests->total(), 'per_page' => $requests->perPage(), 'current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage()]]);
    }

    public function decideReturn(MarketplaceRequestDecisionRequest $request, string $return, MarketplaceOrderRequestService $service, MarketplaceAuditLogger $audit): JsonResponse
    {
        $model = MarketplaceReturnRequest::where('uuid', $return)->firstOrFail();
        $decided = $service->decideReturn($model, $request->user(), $request->string('decision')->toString(), $request->input('note'), $request->input('approved_amount'));
        $audit->record('marketplace.return_request_decided', $request->user(), entity: $decided, metadata: ['decision' => $decided->status]);

        return response()->json(['success' => true, 'data' => new MarketplaceReturnRequestResource($decided)]);
    }
}
