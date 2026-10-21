<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\MarketplaceApprovalRequest;
use App\Http\Requests\Marketplace\CollectMarketplaceCodPaymentRequest;
use App\Http\Requests\Marketplace\MarketplaceCommissionRuleRequest;
use App\Http\Requests\Marketplace\MarketplaceListingModerationRequest;
use App\Http\Requests\Marketplace\MarketplaceRequestDecisionRequest;
use App\Http\Requests\Marketplace\MarketplaceSettingsUpdateRequest;
use App\Http\Requests\Marketplace\MarketplaceStoreStatusRequest;
use App\Http\Resources\MarketplaceCancellationRequestResource;
use App\Http\Resources\MarketplaceOrderResource;
use App\Http\Resources\MarketplaceRefundRequestResource;
use App\Http\Resources\MarketplaceReturnRequestResource;
use App\Http\Resources\MarketplaceShipmentResource;
use App\Http\Resources\MarketplaceStoreResource;
use App\Models\MarketplaceCancellationRequest;
use App\Models\MarketplaceAuditLog;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceCommissionRule;
use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceRefundRequest;
use App\Models\MarketplaceReturnRequest;
use App\Models\MarketplaceShipment;
use App\Models\MarketplaceStore;
use App\Services\MarketplaceAuditLogger;
use App\Services\MarketplaceCommissionService;
use App\Services\MarketplaceOrderRequestService;
use App\Services\MarketplaceSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;

class MarketplaceAdminController
{
    public function dashboard(Request $request, MarketplaceCommissionService $commissions): JsonResponse
    {
        $range = $this->dateRange($request);
        $financialTotals = $commissions->dashboardTotals($range);
        $publishedListings = MarketplaceListing::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereHas('store', fn (Builder $store) => $store->public())
            ->count();
        $grossSales = MarketplaceOrder::query()->whereIn('payment_status', ['paid', 'authorized'])->whereBetween('created_at', $range)->sum('grand_total');
        $refundTotal = MarketplaceRefundRequest::query()->whereIn('status', ['approved'])->whereBetween('created_at', $range)->sum('approved_amount');

        return response()->json([
            'success' => true,
            'data' => [
                'range' => ['from' => $range[0]->toIso8601String(), 'to' => $range[1]->toIso8601String()],
                'metrics' => [
                    'approved_vendors' => MarketplaceStore::query()->where('approval_status', 'approved')->where('is_active', true)->whereHas('tenant', fn (Builder $tenant) => $tenant->where('status', 'active'))->count(),
                    'pending_vendor_approvals' => MarketplaceStore::query()->where('approval_status', 'pending')->count(),
                    'published_products' => $publishedListings,
                    'marketplace_orders' => MarketplaceOrder::query()->whereBetween('created_at', $range)->count(),
                    'gross_sales' => $this->money($grossSales),
                    'successful_payments' => MarketplacePayment::query()->where('status', 'paid')->whereBetween('paid_at', $range)->count(),
                    'pending_fulfillment' => DB::table('marketplace_vendor_orders')->whereIn('status', ['pending', 'processing'])->whereBetween('created_at', $range)->count(),
                    'refund_total' => $this->money($refundTotal),
                    'platform_commission_revenue' => $financialTotals['commission_revenue'],
                    'outstanding_vendor_liabilities' => $financialTotals['vendor_liabilities'],
                ],
                'recent_orders' => MarketplaceOrder::query()->with(['vendorOrders.store'])->whereBetween('created_at', $range)->latest()->limit(8)->get()->map(fn (MarketplaceOrder $order): array => [
                    'id' => $order->uuid,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'grand_total' => $order->grand_total,
                    'currency_code' => $order->currency_code,
                    'vendor_count' => $order->vendorOrders->count(),
                    'created_at' => $order->created_at?->toIso8601String(),
                ])->values(),
                'moderation_activity' => $this->moderationActivity(),
                'financial_capabilities' => $this->financialCapabilities(),
            ],
        ]);
    }

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

    public function storeStatus(MarketplaceStoreStatusRequest $request, MarketplaceStore $store, MarketplaceAuditLogger $audit): JsonResponse
    {
        $wasActive = (bool) $store->is_active;
        $isActive = $request->boolean('is_active');
        $store->forceFill(['is_active' => $isActive])->save();
        $audit->record('marketplace.store_status_updated', $request->user(), $store->tenant, $store, [
            'from_active' => $wasActive,
            'to_active' => $isActive,
            'reason' => $request->input('reason'),
        ]);

        return response()->json(['success' => true, 'data' => new MarketplaceStoreResource($store->refresh()->load('tenant'))]);
    }

    public function listings(Request $request): JsonResponse
    {
        $listings = MarketplaceListing::query()
            ->with(['store.tenant', 'tenant', 'product.category', 'product.images', 'product.variants.balances', 'images'])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), fn (Builder $query) => $query->where(function (Builder $search) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $search->where('marketplace_listings.slug', 'ilike', $term)
                    ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'ilike', $term))
                    ->orWhereHas('store', fn (Builder $store) => $store->where('name', 'ilike', $term));
            }))
            ->latest('marketplace_listings.created_at')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\MarketplaceListingResource::collection($listings),
            'meta' => ['total' => $listings->total(), 'per_page' => $listings->perPage(), 'current_page' => $listings->currentPage(), 'last_page' => $listings->lastPage()],
        ]);
    }

    public function moderateListing(MarketplaceListingModerationRequest $request, MarketplaceListing $listing, MarketplaceAuditLogger $audit): JsonResponse
    {
        $listing->load(['store.tenant', 'product']);
        $status = $request->string('status')->toString();
        if ($status === 'published' && ($listing->store?->approval_status !== 'approved' || ! $listing->store?->is_active || $listing->store?->tenant?->status !== 'active' || $listing->product?->status !== 'active' || ! $listing->product?->variants()->where('status', 'active')->exists())) {
            throw ValidationException::withMessages(['status' => ['Only products belonging to an approved active store with an active product variant can be published.']]);
        }

        $previous = $listing->status;
        $listing->forceFill(['status' => $status, 'published_at' => $status === 'published' ? ($listing->published_at ?? now()) : null])->save();
        $audit->record('marketplace.listing_moderated', $request->user(), $listing->tenant, $listing, ['from_status' => $previous, 'to_status' => $status, 'reason' => $request->input('reason')]);

        return response()->json(['success' => true, 'data' => new \App\Http\Resources\MarketplaceListingResource($listing->refresh()->load($this->listingRelations()))]);
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

    public function collectCod(CollectMarketplaceCodPaymentRequest $request, string $order, MarketplaceAuditLogger $audit, MarketplaceCommissionService $commissions): JsonResponse
    {
        $model = DB::transaction(function () use ($request, $order): MarketplaceOrder {
            $model = MarketplaceOrder::where('uuid', $order)->lockForUpdate()->firstOrFail();
            $payment = $model->payments()->where('method', 'cod')->lockForUpdate()->firstOrFail();
            if ($payment->status === 'pending') {
                $payment->update(['status' => 'paid', 'paid_at' => now(), 'metadata' => array_merge((array) $payment->metadata, ['collected_by' => $request->user()->uuid, 'verification_note' => $request->input('verification_note')])]);
                $model->update(['payment_status' => 'paid']);
                $commissions->markOrderPayable($model);
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

    public function reports(Request $request): JsonResponse
    {
        $range = $this->dateRange($request);
        $orders = MarketplaceOrder::query()->whereBetween('created_at', $range);
        $vendorOrders = DB::table('marketplace_vendor_orders');
        $byStatus = (clone $orders)->select('status')->selectRaw('COUNT(*)::int AS count')->groupBy('status')->orderBy('status')->get()->map(fn ($row): array => ['status' => $row->status, 'count' => (int) $row->count])->values();
        $byPayment = (clone $orders)->select('payment_status')->selectRaw('COUNT(*)::int AS count')->groupBy('payment_status')->orderBy('payment_status')->get()->map(fn ($row): array => ['status' => $row->payment_status, 'count' => (int) $row->count])->values();
        $byVendor = (clone $vendorOrders)->join('marketplace_stores', 'marketplace_stores.id', '=', 'marketplace_vendor_orders.marketplace_store_id')->join('tenants', 'tenants.id', '=', 'marketplace_vendor_orders.tenant_id')->select('marketplace_vendor_orders.tenant_id', 'tenants.name AS business_name', 'marketplace_stores.name AS store_name')->selectRaw('COUNT(*)::int AS order_count')->selectRaw('COALESCE(SUM(marketplace_vendor_orders.total), 0) AS gross_sales')->whereBetween('marketplace_vendor_orders.created_at', $range)->groupBy('marketplace_vendor_orders.tenant_id', 'tenants.name', 'marketplace_stores.name')->orderByDesc('gross_sales')->limit(50)->get()->map(fn ($row): array => ['tenant_id' => (int) $row->tenant_id, 'business_name' => $row->business_name, 'store_name' => $row->store_name, 'order_count' => (int) $row->order_count, 'gross_sales' => $this->money($row->gross_sales)])->values();

        return response()->json(['success' => true, 'data' => [
            'range' => ['from' => $range[0]->toIso8601String(), 'to' => $range[1]->toIso8601String()],
            'summary' => ['orders' => (clone $orders)->count(), 'gross_sales' => $this->money((clone $orders)->whereIn('payment_status', ['paid', 'authorized'])->sum('grand_total')), 'refund_total' => $this->money(MarketplaceRefundRequest::whereIn('status', ['approved'])->whereBetween('created_at', $range)->sum('approved_amount'))],
            'orders_by_status' => $byStatus,
            'payments_by_status' => $byPayment,
            'vendors' => $byVendor,
            'financial_capabilities' => $this->financialCapabilities(),
        ]]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $logs = MarketplaceAuditLog::query()->with(['actor:id,name,email', 'tenant:id,name,business_code'])
            ->when($request->filled('action'), fn (Builder $query) => $query->where('action', $request->string('action')))
            ->when($request->filled('tenant_id'), fn (Builder $query) => $query->where('tenant_id', $request->integer('tenant_id')))
            ->when($request->filled('search'), fn (Builder $query) => $query->where('action', 'ilike', '%'.$request->string('search').'%'))
            ->latest('created_at')
            ->paginate(min(max((int) $request->input('per_page', 50), 1), 100));

        $data = $logs->getCollection()->map(fn (MarketplaceAuditLog $log): array => [
            'id' => $log->id,
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'actor' => $log->actor ? ['name' => $log->actor->name, 'email' => $log->actor->email] : null,
            'business' => $log->tenant ? ['name' => $log->tenant->name, 'business_code' => $log->tenant->business_code] : null,
            'metadata' => $log->metadata ?: [],
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values();

        return response()->json(['success' => true, 'data' => $data, 'meta' => ['total' => $logs->total(), 'per_page' => $logs->perPage(), 'current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage()]]);
    }

    public function settings(MarketplaceSettingsService $settings, MarketplaceCommissionService $commissions): JsonResponse
    {
        $current = $settings->current();
        return response()->json(['success' => true, 'data' => [
            'configured' => $current['configured'],
            'settings' => $current['settings'],
            'message' => $current['configured'] ? 'Marketplace settings are persisted.' : 'The reviewed marketplace settings migration is pending; defaults are shown and saving is disabled until it is applied.',
            'financial_capabilities' => $this->financialCapabilities($settings, $commissions),
        ]]);
    }

    public function updateSettings(MarketplaceSettingsUpdateRequest $request, MarketplaceSettingsService $settings, MarketplaceAuditLogger $audit): JsonResponse
    {
        $data = $request->validated();
        $current = $settings->current()['settings'];
        if (isset($data['revision']) && (int) $data['revision'] !== (int) $current['revision']) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('These marketplace settings changed in another session. Reload before saving.');
        }
        unset($data['revision']);
        $saved = $settings->update($data, $request->user(), $audit);

        return response()->json(['success' => true, 'data' => $settings->current()['settings']]);
    }

    public function commissionRules(Request $request, MarketplaceSettingsService $settings): JsonResponse
    {
        if (! $settings->storageAvailable()) {
            throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(null, 'Commission rule storage is not available. Apply the reviewed marketplace settings migration first.');
        }
        $rules = MarketplaceCommissionRule::query()->with('tenant:id,name')->when($request->filled('tenant_id'), fn (Builder $query) => $query->where('tenant_id', $request->integer('tenant_id')))->orderByDesc('effective_from')->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => $rules->getCollection()->map(fn (MarketplaceCommissionRule $rule): array => ['id' => $rule->uuid, 'tenant_id' => $rule->tenant_id, 'business_name' => $rule->tenant?->name, 'rate' => (string) $rule->rate, 'commission_base' => $rule->commission_base, 'status' => $rule->status, 'effective_from' => $rule->effective_from?->toIso8601String(), 'effective_until' => $rule->effective_until?->toIso8601String()])->values(), 'meta' => ['total' => $rules->total(), 'per_page' => $rules->perPage(), 'current_page' => $rules->currentPage(), 'last_page' => $rules->lastPage()]]);
    }

    public function storeCommissionRule(MarketplaceCommissionRuleRequest $request, MarketplaceAuditLogger $audit, MarketplaceSettingsService $settings): JsonResponse
    {
        if (! $settings->storageAvailable()) {
            throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(null, 'Commission rule storage is not available. Apply the reviewed marketplace settings migration first.');
        }
        $data = $request->validated();
        $effectiveFrom = isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from']) : CarbonImmutable::now();
        $rule = DB::transaction(function () use ($data, $effectiveFrom, $request, $audit): MarketplaceCommissionRule {
            $scope = MarketplaceCommissionRule::query()->where('status', 'active')->where('effective_from', '<=', $effectiveFrom)->whereNull('effective_until');
            $data['tenant_id'] = $data['tenant_id'] ?? null;
            $data['commission_base'] = $data['commission_base'] ?? 'item_subtotal';
            $data['status'] = $data['status'] ?? 'active';
            $data['effective_from'] = $effectiveFrom;
            if ($data['status'] === 'active') {
                $scope->when($data['tenant_id'] === null, fn (Builder $query) => $query->whereNull('tenant_id'), fn (Builder $query) => $query->where('tenant_id', $data['tenant_id']))->lockForUpdate()->update(['status' => 'inactive', 'effective_until' => $effectiveFrom, 'updated_by' => $request->user()->id]);
            }
            $rule = MarketplaceCommissionRule::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $audit->record('marketplace.commission_rule_created', $request->user(), metadata: ['rule_id' => $rule->uuid, 'tenant_id' => $rule->tenant_id, 'rate' => (string) $rule->rate, 'effective_from' => $rule->effective_from?->toIso8601String()]);

            return $rule;
        });

        return response()->json(['success' => true, 'data' => ['id' => $rule->uuid, 'tenant_id' => $rule->tenant_id, 'rate' => (string) $rule->rate, 'commission_base' => $rule->commission_base, 'status' => $rule->status, 'effective_from' => $rule->effective_from?->toIso8601String()]], 201);
    }

    public function updateCommissionRule(MarketplaceCommissionRuleRequest $request, string $rule, MarketplaceAuditLogger $audit, MarketplaceSettingsService $settings): JsonResponse
    {
        if (! $settings->storageAvailable()) {
            throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(null, 'Commission rule storage is not available. Apply the reviewed marketplace settings migration first.');
        }
        $existing = MarketplaceCommissionRule::query()->where('uuid', $rule)->firstOrFail();
        $data = $request->validated();
        $effectiveFrom = isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from']) : CarbonImmutable::now();
        $replacement = DB::transaction(function () use ($existing, $data, $effectiveFrom, $request, $audit): MarketplaceCommissionRule {
            $locked = MarketplaceCommissionRule::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'active' && $locked->effective_until === null) {
                $locked->update(['status' => 'inactive', 'effective_until' => $effectiveFrom, 'updated_by' => $request->user()->id]);
            }
            $attributes = [
                'tenant_id' => array_key_exists('tenant_id', $data) ? $data['tenant_id'] : $locked->tenant_id,
                'rate' => $data['rate'],
                'commission_base' => $data['commission_base'] ?? $locked->commission_base,
                'status' => $data['status'] ?? 'active',
                'effective_from' => $effectiveFrom,
                'effective_until' => $data['effective_until'] ?? null,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ];
            $replacement = MarketplaceCommissionRule::create($attributes);
            $audit->record('marketplace.commission_rule_replaced', $request->user(), metadata: ['replaced_rule_id' => $locked->uuid, 'replacement_rule_id' => $replacement->uuid, 'rate' => (string) $replacement->rate]);

            return $replacement;
        });

        return response()->json(['success' => true, 'data' => ['id' => $replacement->uuid, 'tenant_id' => $replacement->tenant_id, 'rate' => (string) $replacement->rate, 'commission_base' => $replacement->commission_base, 'status' => $replacement->status, 'effective_from' => $replacement->effective_from?->toIso8601String()]]);
    }

    public function commissionPreview(Request $request, MarketplaceCommissionService $commissions): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'integer', 'exists:tenants,id'], 'base_amount' => ['required', 'numeric', 'min:0'], 'currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'], 'at' => ['nullable', 'date']]);

        return response()->json(['success' => true, 'data' => $commissions->preview((int) $data['tenant_id'], (string) $data['base_amount'], $data['currency_code'] ?? null, isset($data['at']) ? CarbonImmutable::parse($data['at']) : null)]);
    }

    public function commissionReports(Request $request, MarketplaceCommissionService $commissions): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['nullable', 'integer', 'exists:tenants,id'], 'status' => ['nullable', 'in:pending,payable,reversed'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(['success' => true, 'data' => $commissions->report($data)]);
    }

    public function payoutSummaries(Request $request, MarketplaceCommissionService $commissions): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['nullable', 'integer', 'exists:tenants,id'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(['success' => true, 'data' => $commissions->payoutSummaries($data)]);
    }

    private function dateRange(Request $request): array
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $days = (int) ($data['days'] ?? 30);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : now()->subDays($days - 1)->startOfDay();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : now()->endOfDay();
        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => ['The report start date must be before the end date.']]);
        }

        return [$from, $to];
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function financialCapabilities(?MarketplaceSettingsService $settings = null, ?MarketplaceCommissionService $commissions = null): array
    {
        $settings ??= app(MarketplaceSettingsService::class);
        $commissions ??= app(MarketplaceCommissionService::class);
        $settingsAvailable = $settings->storageAvailable();
        $ledgerAvailable = $commissions->storageAvailable();
        return [
            'commission_rules' => ['available' => $settingsAvailable, 'reason' => $settingsAvailable ? 'Commission rates and seller overrides are persisted with effective dates.' : 'The reviewed commission migration is pending.'],
            'settlement_ledger' => ['available' => $ledgerAvailable, 'reason' => $ledgerAvailable ? 'Order-level commission snapshots and seller earnings are persisted.' : 'The reviewed settlement migration is pending.'],
            'payouts' => ['available' => false, 'reason' => 'No payout provider or payout records exist yet.'],
            'real_money_refunds' => ['available' => false, 'reason' => 'No verified refund provider is configured.'],
            'disputes' => ['available' => false, 'reason' => 'No dispute workflow or evidence schema exists yet.'],
        ];
    }

    private function moderationActivity(): array
    {
        return MarketplaceAuditLog::query()->with(['actor:id,name', 'tenant:id,name'])->whereIn('action', ['marketplace.store_approval_updated', 'marketplace.store_status_updated', 'marketplace.listing_moderated'])->latest('created_at')->limit(8)->get()->map(fn (MarketplaceAuditLog $log): array => ['id' => $log->id, 'action' => $log->action, 'actor' => $log->actor?->name, 'business' => $log->tenant?->name, 'created_at' => $log->created_at?->toIso8601String(), 'metadata' => $log->metadata ?: []])->values()->all();
    }

    private function listingRelations(): array
    {
        return ['store.tenant', 'tenant', 'product.category', 'product.images', 'product.variants.balances', 'images'];
    }
}
