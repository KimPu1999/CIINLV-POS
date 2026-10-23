<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\CollectMarketplaceCodPaymentRequest;
use App\Http\Requests\Marketplace\MarketplaceApprovalRequest;
use App\Http\Requests\Marketplace\MarketplaceCommissionRuleRequest;
use App\Http\Requests\Marketplace\MarketplaceListingModerationRequest;
use App\Http\Requests\Marketplace\MarketplaceRequestDecisionRequest;
use App\Http\Requests\Marketplace\MarketplaceSettingsUpdateRequest;
use App\Http\Requests\Marketplace\MarketplaceStoreStatusRequest;
use App\Http\Resources\MarketplaceCancellationRequestResource;
use App\Http\Resources\MarketplaceListingResource;
use App\Http\Resources\MarketplaceOrderResource;
use App\Http\Resources\MarketplaceRefundRequestResource;
use App\Http\Resources\MarketplaceReturnRequestResource;
use App\Http\Resources\MarketplaceShipmentResource;
use App\Http\Resources\MarketplaceStoreResource;
use App\Http\Resources\MarketplaceVendorOrderResource;
use App\Models\Category;
use App\Models\MarketplaceAuditLog;
use App\Models\MarketplaceCancellationRequest;
use App\Models\MarketplaceCommissionRule;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderCommission;
use App\Models\MarketplacePayment;
use App\Models\MarketplacePayout;
use App\Models\MarketplaceRefundRequest;
use App\Models\MarketplaceReturnRequest;
use App\Models\MarketplaceShipment;
use App\Models\MarketplaceStore;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Services\BusinessNotificationService;
use App\Services\MarketplaceAuditLogger;
use App\Services\MarketplaceCommissionService;
use App\Services\MarketplaceDocumentService;
use App\Services\MarketplaceFulfillmentService;
use App\Services\MarketplaceOrderRequestService;
use App\Services\MarketplacePaymentService;
use App\Services\MarketplacePayoutService;
use App\Services\MarketplaceSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

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
            ->withCount(['listings', 'vendorOrders'])
            ->when($request->filled('approval_status'), fn (Builder $query) => $query->where('approval_status', $request->string('approval_status')))
            ->when($request->has('active') && in_array($request->input('active'), ['0', '1', 0, 1, false, true], true), fn (Builder $query) => $query->where('is_active', $request->boolean('active')))
            ->when($request->filled('business_id'), fn (Builder $query) => $query->whereHas('tenant', function (Builder $tenant) use ($request): void {
                $value = $request->string('business_id')->toString();
                $tenant->where('uuid', $value)->orWhere('business_code', $value)->orWhere('name', 'ilike', '%'.$value.'%');
            }))
            ->when($request->filled('search'), fn (Builder $query) => $query->where(function (Builder $search) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $search->where('marketplace_stores.name', 'ilike', $term)
                    ->orWhereHas('tenant', fn (Builder $tenant) => $tenant->where('tenants.name', 'ilike', $term)->orWhere('tenants.business_code', 'ilike', $term));
            }))
            ->when(in_array($request->input('sort'), ['name', 'created_at', 'listings_count', 'vendor_orders_count'], true), fn (Builder $query) => $query->orderBy($request->input('sort'), $request->input('direction') === 'asc' ? 'asc' : 'desc'))
            ->when(! in_array($request->input('sort'), ['name', 'created_at', 'listings_count', 'vendor_orders_count'], true), fn (Builder $query) => $query->orderByDesc('marketplace_stores.created_at'))
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        $summary = [
            'total' => MarketplaceStore::query()->count(),
            'approved' => MarketplaceStore::query()->where('approval_status', 'approved')->count(),
            'pending' => MarketplaceStore::query()->where('approval_status', 'pending')->count(),
            'suspended' => MarketplaceStore::query()->where('is_active', false)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => MarketplaceStoreResource::collection($stores),
            'meta' => ['total' => $stores->total(), 'per_page' => $stores->perPage(), 'current_page' => $stores->currentPage(), 'last_page' => $stores->lastPage(), 'summary' => $summary],
        ]);
    }

    public function vendorDetail(Request $request, MarketplaceStore $store, MarketplaceCommissionService $commissions): JsonResponse
    {
        $range = $this->dateRange($request);
        $store->load('tenant');
        $listingPage = $store->listings()
            ->with($this->listingRelations())
            ->when($request->filled('listing_status'), fn (Builder $query) => $query->where('status', $request->string('listing_status')))
            ->latest('marketplace_listings.created_at')
            ->paginate(min(max((int) $request->input('listing_per_page', 12), 1), 50), ['*'], 'listing_page');
        $orderPage = $store->vendorOrders()
            ->with(['store', 'tenant', 'items', 'shipments.events', 'order.customer:id,name,email', 'order.payments'])
            ->when($request->filled('order_status'), fn (Builder $query) => $query->where('status', $request->string('order_status')))
            ->latest('marketplace_vendor_orders.created_at')
            ->paginate(min(max((int) $request->input('order_per_page', 12), 1), 50), ['*'], 'order_page');
        $rangeOrders = $store->vendorOrders()->whereBetween('created_at', $range);
        $paidRangeOrders = (clone $rangeOrders)->whereHas('order', fn (Builder $order) => $order->whereIn('payment_status', ['paid', 'authorized']));
        $delivered = (clone $rangeOrders)->where(function (Builder $query): void {
            $query->where('status', 'delivered')->orWhere('fulfillment_status', 'delivered');
        })->count();
        $cancelled = (clone $rangeOrders)->where('status', 'cancelled')->count();
        $totalOrders = (clone $rangeOrders)->count();
        $lowStock = $store->listings()->whereHas('product', function (Builder $product): void {
            $product->where('track_inventory', true)->whereHas('variants', fn (Builder $variant) => $variant->whereRaw('EXISTS (SELECT 1 FROM inventory_balances WHERE inventory_balances.product_variant_id = product_variants.id AND (inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level)'));
        })->count();
        $returnRequests = MarketplaceReturnRequest::query()->whereHas('order.vendorOrders', fn (Builder $vendor) => $vendor->where('marketplace_store_id', $store->id))->whereBetween('created_at', $range)->count();
        $financialSummary = $commissions->businessSummaries([$store->tenant_id])[$store->tenant_id] ?? null;
        $earnings = $commissions->tenantEarnings($store->tenant_id, $store->tenant?->currency_code);
        $audits = MarketplaceAuditLog::query()->with(['actor:id,name,email', 'tenant:id,name,business_code'])
            ->where('tenant_id', $store->tenant_id)
            ->where(function (Builder $query) use ($store): void {
                $query->where(function (Builder $entity) use ($store): void {
                    $entity->where('entity_type', MarketplaceStore::class)->where('entity_id', (string) $store->id);
                })->orWhereIn('action', ['marketplace.listing_moderated', 'marketplace.order_investigation_note_added', 'marketplace.order_hold_updated', 'marketplace.order_status_corrected']);
            })->latest('created_at')->limit(100)->get();
        $suspensionAudit = $audits->first(fn (MarketplaceAuditLog $audit): bool => $audit->action === 'marketplace.store_status_updated' && data_get($audit->metadata, 'to_active') === false);

        return response()->json(['success' => true, 'data' => [
            'store' => new MarketplaceStoreResource($store->loadCount(['listings', 'vendorOrders'])),
            'suspension' => $suspensionAudit ? ['reason' => data_get($suspensionAudit->metadata, 'reason'), 'actor' => $suspensionAudit->actor?->only(['id', 'name', 'email']), 'at' => $suspensionAudit->created_at?->toIso8601String()] : null,
            'summary' => [
                'range' => ['from' => $range[0]->toIso8601String(), 'to' => $range[1]->toIso8601String()],
                'total_orders' => $totalOrders,
                'delivered_orders' => $delivered,
                'cancelled_orders' => $cancelled,
                'fulfillment_completion_rate' => $totalOrders ? $this->percentage($delivered, $totalOrders) : '0.00',
                'cancellation_rate' => $totalOrders ? $this->percentage($cancelled, $totalOrders) : '0.00',
                'gross_sales' => $this->money($paidRangeOrders->sum('total')),
                'return_requests' => $returnRequests,
                'low_stock_listings' => $lowStock,
            ],
            'products' => MarketplaceListingResource::collection($listingPage),
            'orders' => MarketplaceVendorOrderResource::collection($orderPage),
            'financial' => $financialSummary ? ['available' => true, ...$financialSummary, 'payout_status' => $earnings['configured'] ? ($earnings['reserved'] !== '0.00' ? 'reserved' : ($earnings['available'] !== '0.00' ? 'available' : 'pending')) : 'unavailable'] : ['available' => false, 'reason' => 'Marketplace settlement records are not available for this business.'],
            'audit' => $audits->map(fn (MarketplaceAuditLog $log): array => ['id' => $log->id, 'action' => $log->action, 'actor' => $log->actor?->only(['id', 'name', 'email']), 'business' => $log->tenant?->only(['id', 'name', 'business_code']), 'metadata' => $log->metadata ?: [], 'created_at' => $log->created_at?->toIso8601String()])->values(),
            'pagination' => ['products' => ['total' => $listingPage->total(), 'current_page' => $listingPage->currentPage(), 'last_page' => $listingPage->lastPage()], 'orders' => ['total' => $orderPage->total(), 'current_page' => $orderPage->currentPage(), 'last_page' => $orderPage->lastPage()]],
        ]]);
    }

    public function storeStatus(MarketplaceStoreStatusRequest $request, MarketplaceStore $store, MarketplaceAuditLogger $audit): JsonResponse
    {
        $wasActive = (bool) $store->is_active;
        $isActive = $request->boolean('is_active');

        $store->load('tenant');
        if ($isActive && ($store->approval_status !== 'approved' || $store->tenant?->status !== 'active')) {
            throw ValidationException::withMessages([
                'is_active' => ['Only approved stores belonging to active businesses can be restored.'],
            ]);
        }

        $store->forceFill(['is_active' => $isActive])->save();
        $audit->record('marketplace.store_status_updated', $request->user(), $store->tenant, $store, [
            'from_active' => $wasActive,
            'to_active' => $isActive,
            'reason' => $request->input('reason') ? trim(strip_tags($request->input('reason'))) : null,
        ]);

        return response()->json(['success' => true, 'data' => new MarketplaceStoreResource($store->refresh()->load('tenant'))]);
    }

    public function listings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'store_id' => ['nullable', 'string', 'max:100'],
            'business_id' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'stock' => ['nullable', 'in:in_stock,low_stock,out_of_stock'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'sort' => ['nullable', 'in:created_at,name,price,status'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);

        $listings = MarketplaceListing::query()
            ->with($this->listingRelations())
            ->when(! empty($validated['status']), fn (Builder $query) => $query->where('marketplace_listings.status', $validated['status']))
            ->when(! empty($validated['store_id']), fn (Builder $query) => $query->whereHas('store', function (Builder $store) use ($validated): void {
                $value = $validated['store_id'];
                $store->where(fn (Builder $match) => $match->where('uuid', $value)->orWhere('id', is_numeric($value) ? (int) $value : -1));
            }))
            ->when(! empty($validated['business_id']), fn (Builder $query) => $query->whereHas('tenant', function (Builder $tenant) use ($validated): void {
                $value = $validated['business_id'];
                $tenant->where(fn (Builder $match) => $match->where('uuid', $value)->orWhere('business_code', $value)->orWhere('name', 'ilike', '%'.$value.'%'));
            }))
            ->when(! empty($validated['search']), fn (Builder $query) => $query->where(function (Builder $search) use ($validated): void {
                $term = '%'.$validated['search'].'%';
                $search->where('marketplace_listings.slug', 'ilike', $term)
                    ->orWhereHas('product', fn (Builder $product) => $product->where(fn (Builder $match) => $match->where('name', 'ilike', $term)->orWhere('brand', 'ilike', $term)))
                    ->orWhereHas('product.variants', fn (Builder $variant) => $variant->where('sku', 'ilike', $term)->orWhere('barcode', 'ilike', $term))
                    ->orWhereHas('store', fn (Builder $store) => $store->where('name', 'ilike', $term))
                    ->orWhereHas('tenant', fn (Builder $tenant) => $tenant->where('name', 'ilike', $term)->orWhere('business_code', 'ilike', $term));
            }))
            ->when(! empty($validated['category']), fn (Builder $query) => $query->whereHas('product.category', fn (Builder $category) => $category->where('name', 'ilike', '%'.$validated['category'].'%')))
            ->when(! empty($validated['brand']), fn (Builder $query) => $query->whereHas('product', fn (Builder $product) => $product->where('brand', 'ilike', '%'.$validated['brand'].'%')))
            ->when(! empty($validated['min_price']) || ! empty($validated['max_price']), function (Builder $query) use ($validated): void {
                $query->whereHas('product.variants', function (Builder $variant) use ($validated): void {
                    $variant->where('status', 'active')
                        ->when(isset($validated['min_price']), fn (Builder $price) => $price->where('selling_price', '>=', $validated['min_price']))
                        ->when(isset($validated['max_price']), fn (Builder $price) => $price->where('selling_price', '<=', $validated['max_price']));
                });
            })
            ->when(! empty($validated['created_from']), fn (Builder $query) => $query->whereDate('marketplace_listings.created_at', '>=', $validated['created_from']))
            ->when(! empty($validated['created_to']), fn (Builder $query) => $query->whereDate('marketplace_listings.created_at', '<=', $validated['created_to']))
            ->when(! empty($validated['stock']), function (Builder $query) use ($validated): void {
                $query->whereHas('product', function (Builder $product) use ($validated): void {
                    $stock = $validated['stock'];
                    if ($stock === 'in_stock') {
                        $product->where(function (Builder $available): void {
                            $available->where('track_inventory', false)->orWhereHas('variants', fn (Builder $variant) => $variant->where('status', 'active')->whereHas('balances', fn (Builder $balance) => $balance->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0')));
                        });
                    } elseif ($stock === 'low_stock') {
                        $product->where('track_inventory', true)->whereHas('variants', fn (Builder $variant) => $variant->where('status', 'active')->whereHas('balances', fn (Builder $balance) => $balance->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0')->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level')));
                    } else {
                        $product->where('track_inventory', true)->whereDoesntHave('variants', fn (Builder $variant) => $variant->where('status', 'active')->whereHas('balances', fn (Builder $balance) => $balance->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0')));
                    }
                });
            })
            ->when(in_array($validated['sort'] ?? null, ['created_at', 'status'], true), fn (Builder $query) => $query->orderBy('marketplace_listings.'.($validated['sort'] ?? 'created_at'), $validated['direction'] ?? 'desc'))
            ->when(($validated['sort'] ?? null) === 'name', fn (Builder $query) => $query->orderBy(Product::select('name')->whereColumn('products.id', 'marketplace_listings.product_id'), $validated['direction'] ?? 'asc'))
            ->when(($validated['sort'] ?? null) === 'price', fn (Builder $query) => $query->orderBy(ProductVariant::select('selling_price')->whereColumn('product_id', 'marketplace_listings.product_id')->where('status', 'active')->orderByDesc('is_default')->limit(1), $validated['direction'] ?? 'asc'))
            ->when(! in_array($validated['sort'] ?? null, ['created_at', 'name', 'price', 'status'], true), fn (Builder $query) => $query->orderByDesc('marketplace_listings.created_at'))
            ->orderBy('marketplace_listings.id');

        $paginated = $listings->paginate((int) ($validated['per_page'] ?? 25));
        $summary = [
            'total' => MarketplaceListing::query()->count(),
            'published' => MarketplaceListing::query()->where('status', 'published')->count(),
            'draft' => MarketplaceListing::query()->where('status', 'draft')->count(),
            'archived' => MarketplaceListing::query()->where('status', 'archived')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => MarketplaceListingResource::collection($paginated),
            'meta' => ['total' => $paginated->total(), 'per_page' => $paginated->perPage(), 'current_page' => $paginated->currentPage(), 'last_page' => $paginated->lastPage(), 'summary' => $summary],
        ]);
    }

    public function listingDetail(MarketplaceListing $listing): JsonResponse
    {
        $listing->load($this->listingRelations());
        $audits = MarketplaceAuditLog::query()
            ->with(['actor:id,name,email'])
            ->where('entity_type', MarketplaceListing::class)
            ->where('entity_id', (string) $listing->id)
            ->latest('created_at')
            ->limit(100)
            ->get();
        $eligibility = $this->listingEligibility($listing);

        return response()->json(['success' => true, 'data' => [
            ...(new MarketplaceListingResource($listing))->resolve(request()),
            'publishing_eligibility' => $eligibility,
            'moderation_history' => $audits->map(fn (MarketplaceAuditLog $audit): array => [
                'id' => $audit->id,
                'previous_status' => data_get($audit->metadata, 'from_status'),
                'new_status' => data_get($audit->metadata, 'to_status'),
                'reason' => data_get($audit->metadata, 'reason'),
                'actor' => $audit->actor?->only(['name', 'email']),
                'created_at' => $audit->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    public function moderateListing(MarketplaceListingModerationRequest $request, MarketplaceListing $listing, MarketplaceAuditLogger $audit): JsonResponse
    {
        $listing->load(['store.tenant', 'product']);
        $status = $request->string('status')->toString();
        $eligibility = $this->listingEligibility($listing);
        if ($status === 'published' && ! $eligibility['eligible']) {
            throw ValidationException::withMessages(['status' => [$eligibility['reasons'][0] ?? 'Only products belonging to an approved active store with an active product variant can be published.']]);
        }

        $previous = $listing->status;
        $listing->forceFill(['status' => $status, 'published_at' => $status === 'published' ? ($listing->published_at ?? now()) : null])->save();
        $audit->record('marketplace.listing_moderated', $request->user(), $listing->tenant, $listing, ['from_status' => $previous, 'to_status' => $status, 'reason' => $request->input('reason') ? trim(strip_tags($request->input('reason'))) : null]);

        return response()->json(['success' => true, 'data' => new MarketplaceListingResource($listing->refresh()->load($this->listingRelations()))]);
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
        $audit->record('marketplace.store_approval_updated', $request->user(), $store->tenant, $store, ['approval_status' => $status, 'reason' => $request->input('reason') ? trim(strip_tags($request->input('reason'))) : null]);

        return response()->json(['success' => true, 'data' => new MarketplaceStoreResource($store->refresh()->load('tenant'))]);
    }

    public function collectCod(CollectMarketplaceCodPaymentRequest $request, string $order, MarketplaceAuditLogger $audit, MarketplacePaymentService $payments): JsonResponse
    {
        $model = MarketplaceOrder::where('uuid', $order)->firstOrFail();
        $result = $payments->collectCod($model, $request->user(), [
            ...$request->validated(),
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]);
        $model = $result['order'];
        if (! $result['replayed']) {
            $audit->record('marketplace.cod_payment_collected', $request->user(), entity: $model, metadata: ['order_number' => $model->order_number]);
        }

        return response()->json(['success' => true, 'idempotent_replay' => $result['replayed'], 'data' => new MarketplaceOrderResource($model->load(['payments', 'vendorOrders.order', 'vendorOrders.store', 'vendorOrders.items', 'vendorOrders.shipments.events']))]);
    }

    public function orderDetail(string $order): JsonResponse
    {
        $model = MarketplaceOrder::query()->where('uuid', $order)->with(['customer:id,name,email', 'payments', 'vendorOrders.store.tenant', 'vendorOrders.items', 'vendorOrders.shipments.events.actor', 'events', 'adminHoldUser:id,name,email', 'cancellationRequests', 'returnRequests', 'refundRequests'])->firstOrFail();
        $audits = MarketplaceAuditLog::query()->with(['actor:id,name,email', 'tenant:id,name,business_code'])->where('entity_type', MarketplaceOrder::class)->where('entity_id', (string) $model->id)->latest('created_at')->limit(100)->get();

        return response()->json(['success' => true, 'data' => ['order' => new MarketplaceOrderResource($model), 'customer' => $model->customer?->only(['id', 'name', 'email']), 'audit' => $audits->map(fn (MarketplaceAuditLog $audit): array => ['id' => $audit->id, 'action' => $audit->action, 'actor' => $audit->actor?->only(['id', 'name', 'email']), 'business' => $audit->tenant?->only(['id', 'name', 'business_code']), 'metadata' => $audit->metadata, 'created_at' => $audit->created_at?->toIso8601String()])->values()]]);
    }

    public function investigationNote(Request $request, string $order, MarketplaceAuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:2000']]);
        $model = MarketplaceOrder::query()->where('uuid', $order)->with('vendorOrders')->firstOrFail();
        $this->recordOrderIntervention($audit, 'marketplace.order_investigation_note_added', $request->user(), $model, ['note' => trim(strip_tags($data['note']))]);

        return response()->json(['success' => true, 'message' => 'Investigation note recorded.']);
    }

    public function hold(Request $request, string $order, MarketplaceAuditLogger $audit, BusinessNotificationService $notifications): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:hold,release'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $model = DB::transaction(function () use ($data, $order, $request, $audit): MarketplaceOrder {
            $locked = MarketplaceOrder::query()->where('uuid', $order)->lockForUpdate()->firstOrFail();
            $holding = $data['action'] === 'hold';
            if ($holding === (bool) $locked->admin_hold) {
                throw new ConflictHttpException($holding ? 'This order is already on hold.' : 'This order is not on hold.');
            }
            $reason = trim(strip_tags($data['reason']));
            $previous = (bool) $locked->admin_hold;
            $locked->update(['admin_hold' => $holding, 'admin_hold_reason' => $reason, 'admin_hold_by' => $holding ? $request->user()->id : null, 'admin_hold_at' => $holding ? now() : null]);
            $locked->load('vendorOrders');
            $this->recordOrderIntervention($audit, 'marketplace.order_hold_updated', $request->user(), $locked, ['previous_hold' => $previous, 'new_hold' => $holding, 'reason' => $reason]);

            return $locked;
        });
        $notifications->administrativeIntervention($model, $data['action'] === 'hold' ? 'placed on hold' : 'released from hold', trim(strip_tags($data['reason'])));

        return response()->json(['success' => true, 'data' => new MarketplaceOrderResource($model->fresh()->load(['payments', 'vendorOrders.store', 'vendorOrders.shipments.events', 'adminHoldUser']))]);
    }

    public function correctOrderStatus(Request $request, string $order, MarketplaceAuditLogger $audit, BusinessNotificationService $notifications): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:pending,confirmed,processing,partially_shipped,shipped,delivered,cancelled'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $model = DB::transaction(function () use ($data, $order, $request, $audit): MarketplaceOrder {
            $locked = MarketplaceOrder::query()->where('uuid', $order)->lockForUpdate()->firstOrFail();
            $allowed = ['pending' => ['confirmed', 'cancelled'], 'confirmed' => ['processing', 'cancelled'], 'processing' => ['partially_shipped', 'cancelled'], 'partially_shipped' => ['shipped', 'delivered'], 'shipped' => ['partially_shipped', 'delivered'], 'delivered' => [], 'cancelled' => []];
            if (! in_array($data['status'], $allowed[$locked->status] ?? [], true)) {
                throw new ConflictHttpException(sprintf('Order status cannot be corrected from %s to %s.', $locked->status, $data['status']));
            }
            $locked->load('vendorOrders');
            if (! $this->isOrderStatusConsistent($locked, $data['status'])) {
                throw new ConflictHttpException('The requested order status does not match its vendor fulfillment states. Correct the vendor or shipment state first.');
            }
            $previous = $locked->status;
            $reason = trim(strip_tags($data['reason']));
            $locked->update(['status' => $data['status']]);
            $locked->events()->create(['actor_user_id' => $request->user()->id, 'event' => 'admin_order_status_corrected', 'from_status' => $previous, 'to_status' => $data['status'], 'metadata' => ['reason' => $reason]]);
            $this->recordOrderIntervention($audit, 'marketplace.order_status_corrected', $request->user(), $locked, ['previous_status' => $previous, 'new_status' => $data['status'], 'reason' => $reason]);

            return $locked;
        });
        $notifications->administrativeIntervention($model, 'status corrected to '.$data['status'], trim(strip_tags($data['reason'])));

        return response()->json(['success' => true, 'data' => new MarketplaceOrderResource($model->fresh()->load(['payments', 'vendorOrders.store', 'vendorOrders.shipments.events', 'adminHoldUser']))]);
    }

    public function correctShipmentStatus(Request $request, string $shipment, MarketplaceFulfillmentService $fulfillment, MarketplaceAuditLogger $audit, BusinessNotificationService $notifications): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:ready,shipped,in_transit,delivered,delivery_failed,returned,cancelled'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $model = MarketplaceShipment::query()->where('uuid', $shipment)->firstOrFail();
        $previous = $model->status;
        $updated = $fulfillment->correctShipmentStatus($model, $request->user(), $data['status'], trim(strip_tags($data['reason'])));
        $updated->load(['vendorOrder.order', 'tenant']);
        $audit->record('marketplace.shipment_status_corrected', $request->user(), $updated->tenant, $updated, ['previous_status' => $previous, 'new_status' => $data['status'], 'reason' => trim(strip_tags($data['reason']))]);
        $notifications->administrativeIntervention($updated->vendorOrder->order, 'shipment status corrected to '.$data['status'], trim(strip_tags($data['reason'])));

        return response()->json(['success' => true, 'data' => new MarketplaceShipmentResource($updated)]);
    }

    public function invoice(string $order, MarketplaceDocumentService $documents): JsonResponse
    {
        $model = MarketplaceOrder::where('uuid', $order)->firstOrFail();

        return response()->json(['success' => true, 'data' => $documents->invoice($model)]);
    }

    public function receipt(string $order, MarketplaceDocumentService $documents): JsonResponse
    {
        $model = MarketplaceOrder::where('uuid', $order)->firstOrFail();

        return response()->json(['success' => true, 'data' => $documents->receipt($model)]);
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
            throw new ConflictHttpException('These marketplace settings changed in another session. Reload before saving.');
        }
        unset($data['revision']);
        $saved = $settings->update($data, $request->user(), $audit);

        return response()->json(['success' => true, 'data' => $settings->current()['settings']]);
    }

    public function commissionRules(Request $request, MarketplaceSettingsService $settings): JsonResponse
    {
        if (! $settings->storageAvailable()) {
            throw new ServiceUnavailableHttpException(null, 'Commission rule storage is not available. Apply the reviewed marketplace settings migration first.');
        }
        $ruleRelations = ['tenant:id,name,business_code'];
        if (Schema::hasColumn('marketplace_commission_rules', 'category_id')) {
            $ruleRelations[] = 'category:id,name,tenant_id';
        }
        $rules = MarketplaceCommissionRule::query()
            ->with($ruleRelations)
            ->when($request->filled('tenant_id'), fn (Builder $query) => $query->where('tenant_id', $request->integer('tenant_id')))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when(Schema::hasColumn('marketplace_commission_rules', 'rule_type') && $request->filled('rule_type'), fn (Builder $query) => $query->where('rule_type', $request->string('rule_type')))
            ->orderByDesc('effective_from')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => $rules->getCollection()->map(fn (MarketplaceCommissionRule $rule): array => $this->commissionRuleData($rule))->values(), 'meta' => ['total' => $rules->total(), 'per_page' => $rules->perPage(), 'current_page' => $rules->currentPage(), 'last_page' => $rules->lastPage()]]);
    }

    public function commissionBusinesses(Request $request, MarketplaceSettingsService $settings, MarketplaceCommissionService $commissions): JsonResponse
    {
        if (! $settings->storageAvailable()) {
            throw new ServiceUnavailableHttpException(null, 'Commission rule storage is not available. Apply the reviewed marketplace settings migration first.');
        }

        $sort = in_array($request->input('sort'), ['name', 'business_code', 'status', 'created_at'], true) ? $request->input('sort') : 'name';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $businesses = Tenant::query()
            ->select(['id', 'business_code', 'name', 'slug', 'status'])
            ->with(['memberships' => fn ($query) => $query->where('status', 'active')->with(['user:id,name,email', 'role:id,name'])->orderBy('id')])
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $query->where(function (Builder $search) use ($term): void {
                    $search->where('name', 'ilike', $term)
                        ->orWhere('business_code', 'ilike', $term)
                        ->orWhere('slug', 'ilike', $term);
                });
            })
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when(Schema::hasColumn('marketplace_commission_rules', 'rule_type') && $request->input('rule_type') === 'business_override', fn (Builder $query) => $query->whereHas('marketplaceCommissionRules', fn (Builder $rule) => $rule->where('rule_type', 'business')->where('status', 'active')->where('effective_from', '<=', now())->where(fn (Builder $active) => $active->whereNull('effective_until')->orWhere('effective_until', '>', now()))))
            ->when(Schema::hasColumn('marketplace_commission_rules', 'rule_type') && $request->input('rule_type') === 'global_default', fn (Builder $query) => $query->whereDoesntHave('marketplaceCommissionRules', fn (Builder $rule) => $rule->where('rule_type', 'business')->where('status', 'active')->where('effective_from', '<=', now())->where(fn (Builder $active) => $active->whereNull('effective_until')->orWhere('effective_until', '>', now()))))
            ->orderBy($sort, $direction)
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        $settingsUpdatedAt = $settings->current()['settings']['updated_at'];
        $financial = $commissions->businessSummaries($businesses->getCollection()->pluck('id')->all());
        $payouts = Schema::hasTable('marketplace_payouts')
            ? MarketplacePayout::query()->whereIn('tenant_id', $businesses->getCollection()->pluck('id'))->whereIn('status', ['requested', 'approved', 'processing'])->latest()->get()->keyBy('tenant_id')
            : collect();
        $data = $businesses->getCollection()->map(function (Tenant $tenant) use ($commissions, $settingsUpdatedAt, $financial, $payouts): array {
            $effective = $commissions->effectiveRule($tenant->id);
            $effectiveRule = $effective['rule_id']
                ? MarketplaceCommissionRule::query()->find($effective['rule_id'])
                : null;
            $hasOverride = $effective['source'] === 'business';
            $owner = $tenant->memberships->first(fn ($membership) => in_array($membership->role?->name, ['business_admin', 'owner'], true))?->user
                ?? $tenant->memberships->first()?->user;
            $totals = $financial[$tenant->id] ?? ['eligible_sales_total' => '0.00', 'platform_commission_total' => '0.00', 'seller_earnings_total' => '0.00'];

            return [
                'tenant_id' => $tenant->id,
                'business_id' => $tenant->business_code ?: (string) $tenant->id,
                'name' => $tenant->name,
                'owner' => $owner ? ['name' => $owner->name, 'email' => $owner->email] : null,
                'status' => $tenant->status,
                'effective_commission' => (string) $effective['rate'],
                'rule_type' => $hasOverride ? 'business_override' : match ($effective['source']) {
                    'promotional' => 'promotional',
                    'category' => 'category_rule',
                    default => 'global_default',
                },
                'override_rule_id' => $hasOverride ? $effectiveRule?->uuid : null,
                'last_updated' => $effectiveRule?->updated_at?->toIso8601String() ?? $settingsUpdatedAt,
                'eligible_sales_total' => $totals['eligible_sales_total'],
                'platform_commission_total' => $totals['platform_commission_total'],
                'seller_earnings_total' => $totals['seller_earnings_total'],
                'payout_status' => $payouts->get($tenant->id)?->status ?? 'not_requested',
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $data, 'meta' => ['total' => $businesses->total(), 'per_page' => $businesses->perPage(), 'current_page' => $businesses->currentPage(), 'last_page' => $businesses->lastPage()]]);
    }

    public function storeCommissionRule(MarketplaceCommissionRuleRequest $request, MarketplaceAuditLogger $audit, MarketplaceSettingsService $settings): JsonResponse
    {
        if (! $settings->storageAvailable()) {
            throw new ServiceUnavailableHttpException(null, 'Commission rule storage is not available. Apply the reviewed marketplace settings migration first.');
        }
        $data = $request->validated();
        $advanced = Schema::hasColumn('marketplace_commission_rules', 'rule_type');
        $data['rule_type'] = $data['rule_type'] ?? (! empty($data['tenant_id']) ? 'business' : 'global');
        if (! $advanced && ! in_array($data['rule_type'], ['global', 'business'], true)) {
            throw new ServiceUnavailableHttpException(null, 'Advanced commission rules are not available until the reviewed additive migration is applied.');
        }
        $this->validateCommissionRuleScope($data);
        $effectiveFrom = isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from']) : CarbonImmutable::now();
        $rule = DB::transaction(function () use ($data, $effectiveFrom, $request, $audit, $advanced): MarketplaceCommissionRule {
            $scope = MarketplaceCommissionRule::query()->where('status', 'active')->where('effective_from', '<=', $effectiveFrom)->whereNull('effective_until');
            $data['tenant_id'] = $data['tenant_id'] ?? null;
            $data['commission_base'] = $data['commission_base'] ?? 'item_subtotal';
            $data['status'] = $data['status'] ?? 'active';
            $data['effective_from'] = $effectiveFrom;
            if (! $advanced) {
                unset($data['rule_type'], $data['category_id'], $data['promotion_code'], $data['reason'], $data['priority']);
            } else {
                $scope->where('rule_type', $data['rule_type'])
                    ->when($data['category_id'] ?? null, fn (Builder $query, $categoryId) => $query->where('category_id', $categoryId), fn (Builder $query) => $query->whereNull('category_id'))
                    ->when($data['promotion_code'] ?? null, fn (Builder $query, $code) => $query->where('promotion_code', $code), fn (Builder $query) => $query->whereNull('promotion_code'));
            }
            $scope->when($data['tenant_id'] === null, fn (Builder $query) => $query->whereNull('tenant_id'), fn (Builder $query) => $query->where('tenant_id', $data['tenant_id']));
            $previous = (clone $scope)->orderByDesc('effective_from')->lockForUpdate()->first();
            if ($data['status'] === 'active') {
                $scope->when($data['tenant_id'] === null, fn (Builder $query) => $query->whereNull('tenant_id'), fn (Builder $query) => $query->where('tenant_id', $data['tenant_id']))->update(['status' => 'inactive', 'effective_until' => $effectiveFrom, 'updated_by' => $request->user()->id]);
            }
            $rule = MarketplaceCommissionRule::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $audit->record('marketplace.commission_rule_created', $request->user(), $rule->tenant, $rule, [
                'rule_id' => $rule->uuid,
                'tenant_id' => $rule->tenant_id,
                'business_id' => $rule->tenant?->business_code,
                'old_rate' => $previous?->rate === null ? null : (string) $previous->rate,
                'new_rate' => (string) $rule->rate,
                'old_rule_type' => $previous ? $this->commissionRuleType($previous) : null,
                'new_rule_type' => $this->commissionRuleType($rule),
                'effective_from' => $rule->effective_from?->toIso8601String(),
                'reason' => $rule->reason,
            ]);

            return $rule;
        });

        return response()->json(['success' => true, 'data' => $this->commissionRuleData($rule)], 201);
    }

    public function updateCommissionRule(MarketplaceCommissionRuleRequest $request, string $rule, MarketplaceAuditLogger $audit, MarketplaceSettingsService $settings): JsonResponse
    {
        if (! $settings->storageAvailable()) {
            throw new ServiceUnavailableHttpException(null, 'Commission rule storage is not available. Apply the reviewed marketplace settings migration first.');
        }
        $existing = MarketplaceCommissionRule::query()->where('uuid', $rule)->firstOrFail();
        $data = $request->validated();
        $advanced = Schema::hasColumn('marketplace_commission_rules', 'rule_type');
        $data['tenant_id'] = array_key_exists('tenant_id', $data) ? $data['tenant_id'] : $existing->tenant_id;
        $data['rule_type'] = $data['rule_type'] ?? ($advanced ? ($existing->rule_type ?: ($data['tenant_id'] ? 'business' : 'global')) : ($data['tenant_id'] ? 'business' : 'global'));
        $data['category_id'] = array_key_exists('category_id', $data) ? $data['category_id'] : $existing->category_id;
        $this->validateCommissionRuleScope($data);
        $effectiveFrom = isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from']) : CarbonImmutable::now();
        $replacement = DB::transaction(function () use ($existing, $data, $effectiveFrom, $request, $audit, $advanced): MarketplaceCommissionRule {
            $locked = MarketplaceCommissionRule::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'active' && $locked->effective_until === null) {
                $locked->update(['status' => 'inactive', 'effective_until' => $effectiveFrom, 'updated_by' => $request->user()->id]);
            }
            $attributes = [
                'tenant_id' => $data['tenant_id'],
                'rate' => $data['rate'],
                'commission_base' => $data['commission_base'] ?? $locked->commission_base,
                'status' => $data['status'] ?? 'active',
                'effective_from' => $effectiveFrom,
                'effective_until' => $data['effective_until'] ?? null,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ];
            if ($advanced) {
                $attributes += [
                    'rule_type' => $data['rule_type'],
                    'category_id' => $data['category_id'] ?? null,
                    'promotion_code' => $data['promotion_code'] ?? $locked->promotion_code,
                    'reason' => $data['reason'] ?? $locked->reason,
                    'priority' => $data['priority'] ?? $locked->priority ?? 0,
                ];
            }
            $replacement = MarketplaceCommissionRule::create($attributes);
            $audit->record('marketplace.commission_rule_replaced', $request->user(), $replacement->tenant, $replacement, [
                'replaced_rule_id' => $locked->uuid,
                'replacement_rule_id' => $replacement->uuid,
                'tenant_id' => $replacement->tenant_id,
                'old_rate' => (string) $locked->rate,
                'new_rate' => (string) $replacement->rate,
                'old_rule_type' => $this->commissionRuleType($locked),
                'new_rule_type' => $this->commissionRuleType($replacement),
                'effective_from' => $replacement->effective_from?->toIso8601String(),
                'reason' => $replacement->reason,
            ]);

            return $replacement;
        });

        return response()->json(['success' => true, 'data' => $this->commissionRuleData($replacement)]);
    }

    public function removeCommissionRule(Request $request, string $rule, MarketplaceAuditLogger $audit, MarketplaceSettingsService $settings): JsonResponse
    {
        if (! $settings->storageAvailable()) {
            throw new ServiceUnavailableHttpException(null, 'Commission rule storage is not available. Apply the reviewed marketplace settings migration first.');
        }

        $removed = DB::transaction(function () use ($request, $rule, $audit): MarketplaceCommissionRule {
            $existing = MarketplaceCommissionRule::query()->where('uuid', $rule)->lockForUpdate()->firstOrFail();
            if ($existing->tenant_id === null) {
                throw ValidationException::withMessages(['rule' => ['The global default cannot be removed. Update the global setting instead.']]);
            }

            $wasActive = $existing->status === 'active' && $existing->effective_until === null;
            $hasCommittedOrders = Schema::hasTable('marketplace_order_commissions')
                && MarketplaceOrderCommission::query()->where('marketplace_commission_rule_id', $existing->id)->exists();
            if ($wasActive) {
                $existing->forceFill(['status' => 'inactive', 'effective_until' => now(), 'updated_by' => $request->user()->id])->save();
            }

            $audit->record('marketplace.commission_rule_removed', $request->user(), $existing->tenant, $existing, [
                'rule_id' => $existing->uuid,
                'tenant_id' => $existing->tenant_id,
                'business_id' => $existing->tenant?->business_code,
                'old_rate' => (string) $existing->rate,
                'new_rate' => null,
                'old_rule_type' => $this->commissionRuleType($existing),
                'new_rule_type' => 'global_default',
                'was_active' => $wasActive,
                'has_committed_orders' => $hasCommittedOrders,
            ]);

            return $existing->refresh();
        });

        return response()->json(['success' => true, 'data' => $this->commissionRuleData($removed), 'message' => 'Business override removed. The global default now applies.']);
    }

    public function commissionPreview(Request $request, MarketplaceCommissionService $commissions): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'integer', 'exists:tenants,id'], 'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'promotion_code' => ['nullable', 'string', 'max:100'], 'base_amount' => ['required', 'numeric', 'min:0'], 'currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'], 'at' => ['nullable', 'date']]);
        if (! empty($data['category_id']) && (int) Category::query()->findOrFail($data['category_id'])->tenant_id !== (int) $data['tenant_id']) {
            throw ValidationException::withMessages(['category_id' => ['The category does not belong to the selected business.']]);
        }

        return response()->json(['success' => true, 'data' => $commissions->preview((int) $data['tenant_id'], (string) $data['base_amount'], $data['currency_code'] ?? null, isset($data['at']) ? CarbonImmutable::parse($data['at']) : null, $data['category_id'] ?? null, $data['promotion_code'] ?? null)]);
    }

    public function commissionReports(Request $request, MarketplaceCommissionService $commissions): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['nullable', 'integer', 'exists:tenants,id'], 'status' => ['nullable', 'in:pending,payable,reversed'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(['success' => true, 'data' => $commissions->report($data)]);
    }

    public function exportCommissionReport(Request $request)
    {
        $data = $request->validate(['tenant_id' => ['nullable', 'integer', 'exists:tenants,id'], 'status' => ['nullable', 'in:pending,payable,reversed'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $query = MarketplaceOrderCommission::query()
            ->when($data['tenant_id'] ?? null, fn (Builder $builder, $tenantId) => $builder->where('tenant_id', $tenantId))
            ->when($data['status'] ?? null, fn (Builder $builder, $status) => $builder->where('status', $status))
            ->when($data['from'] ?? null, fn (Builder $builder, $from) => $builder->whereDate('created_at', '>=', $from))
            ->when($data['to'] ?? null, fn (Builder $builder, $to) => $builder->whereDate('created_at', '<=', $to))
            ->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['commission_id', 'order_id', 'vendor_order_id', 'tenant_id', 'currency_code', 'eligible_sales', 'commission_rate', 'commission_amount', 'seller_net_amount', 'status', 'calculated_at']);
            foreach ($query->cursor() as $commission) {
                fputcsv($output, [$commission->uuid, $commission->marketplace_order_id, $commission->marketplace_vendor_order_id, $commission->tenant_id, $commission->currency_code, $commission->commission_base_amount, $commission->commission_rate, $commission->commission_amount, $commission->seller_net_amount, $commission->status, $commission->calculated_at?->toIso8601String()]);
            }
            fclose($output);
        }, 'marketplace-commission-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function payoutSummaries(Request $request, MarketplaceCommissionService $commissions): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['nullable', 'integer', 'exists:tenants,id'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(['success' => true, 'data' => $commissions->payoutSummaries($data)]);
    }

    public function commissionCategories(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $categories = Category::query()
            ->with('tenant:id,name,business_code')
            ->when($data['tenant_id'] ?? null, fn (Builder $query, $tenantId) => $query->where('tenant_id', $tenantId))
            ->when($data['search'] ?? null, fn (Builder $query, $search) => $query->where(function (Builder $where) use ($search): void {
                $term = '%'.$search.'%';
                $where->where('name', 'ilike', $term)->orWhere('slug', 'ilike', $term);
            }))
            ->orderBy('name')
            ->paginate($data['per_page'] ?? 25);

        return response()->json(['success' => true, 'data' => $categories->getCollection()->map(fn (Category $category): array => [
            'id' => $category->id,
            'name' => $category->name,
            'business_id' => $category->tenant?->business_code ?: (string) $category->tenant_id,
            'business_name' => $category->tenant?->name,
        ])->values(), 'meta' => ['total' => $categories->total(), 'per_page' => $categories->perPage(), 'current_page' => $categories->currentPage(), 'last_page' => $categories->lastPage()]]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['nullable', 'integer', 'exists:tenants,id'], 'status' => ['nullable', 'in:requested,approved,processing,paid,rejected,failed,cancelled'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        if (! Schema::hasTable('marketplace_payouts')) {
            throw new ServiceUnavailableHttpException(null, 'Payout storage is not available. Apply the reviewed additive payout migration first.');
        }
        $payouts = MarketplacePayout::query()->with(['tenant:id,name,business_code', 'requester:id,name,email', 'approver:id,name,email'])->when($data['tenant_id'] ?? null, fn (Builder $query, $tenantId) => $query->where('tenant_id', $tenantId))->when($data['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))->latest()->paginate($data['per_page'] ?? 25);

        return response()->json(['success' => true, 'data' => $payouts]);
    }

    public function requestPayout(Request $request, MarketplacePayoutService $payouts, MarketplaceAuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'idempotency_key' => ['required', 'string', 'max:180'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        if (! Schema::hasTable('marketplace_payouts')) {
            throw new ServiceUnavailableHttpException(null, 'Payout storage is not available. Apply the reviewed additive payout migration first.');
        }
        $payout = $payouts->request($data, $request->user());
        $audit->record('marketplace.payout_requested', $request->user(), $payout->tenant, $payout, ['amount' => (string) $payout->amount, 'currency_code' => $payout->currency_code, 'reason' => $payout->reason]);

        return response()->json(['success' => true, 'data' => $payout], 201);
    }

    public function approvePayout(Request $request, string $payout, MarketplacePayoutService $payouts, MarketplaceAuditLogger $audit): JsonResponse
    {
        $model = MarketplacePayout::query()->where('uuid', $payout)->firstOrFail();
        $approved = $payouts->approve($model, $request->user());
        $audit->record('marketplace.payout_approved', $request->user(), $approved->tenant, $approved, ['amount' => (string) $approved->amount, 'currency_code' => $approved->currency_code]);

        return response()->json(['success' => true, 'data' => $approved]);
    }

    public function rejectPayout(Request $request, string $payout, MarketplacePayoutService $payouts, MarketplaceAuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $model = MarketplacePayout::query()->where('uuid', $payout)->firstOrFail();
        $rejected = $payouts->reject($model, $request->user(), $data['reason'] ?? null);
        $audit->record('marketplace.payout_rejected', $request->user(), $rejected->tenant, $rejected, ['reason' => $rejected->reason]);

        return response()->json(['success' => true, 'data' => $rejected]);
    }

    private function validateCommissionRuleScope(array $data): void
    {
        $type = $data['rule_type'];
        if ($type === 'global' && (($data['tenant_id'] ?? null) !== null || ($data['category_id'] ?? null) !== null)) {
            throw ValidationException::withMessages(['rule_type' => ['Global rules cannot be scoped to a business or category.']]);
        }
        if ($type === 'business' && empty($data['tenant_id'])) {
            throw ValidationException::withMessages(['tenant_id' => ['A business is required for a business-specific rule.']]);
        }
        if ($type === 'business' && ! empty($data['category_id'])) {
            throw ValidationException::withMessages(['category_id' => ['Business rules apply to all categories; use a category rule for category-specific pricing.']]);
        }
        if ($type === 'category' && empty($data['category_id'])) {
            throw ValidationException::withMessages(['category_id' => ['A category is required for a category rule.']]);
        }
        if (isset($data['category_id'])) {
            $category = Category::query()->findOrFail($data['category_id']);
            if (! empty($data['tenant_id']) && (int) $category->tenant_id !== (int) $data['tenant_id']) {
                throw ValidationException::withMessages(['category_id' => ['The category does not belong to the selected business.']]);
            }
        }
    }

    private function commissionRuleType(MarketplaceCommissionRule $rule): string
    {
        $type = Schema::hasColumn('marketplace_commission_rules', 'rule_type')
            ? ($rule->rule_type ?: ($rule->tenant_id === null ? 'global' : 'business'))
            : ($rule->tenant_id === null ? 'global' : 'business');

        return match ($type) {
            'business' => 'business_override',
            'category' => 'category_rule',
            'promotional' => 'promotional',
            default => 'global_default',
        };
    }

    private function commissionRuleData(MarketplaceCommissionRule $rule): array
    {
        $relations = ['tenant:id,name,business_code'];
        if (Schema::hasColumn('marketplace_commission_rules', 'category_id')) {
            $relations[] = 'category:id,name,tenant_id';
        }
        $rule->loadMissing($relations);
        $hasAdvancedColumns = Schema::hasColumn('marketplace_commission_rules', 'category_id');

        return [
            'id' => $rule->uuid,
            'tenant_id' => $rule->tenant_id,
            'business_id' => $rule->tenant?->business_code,
            'business_name' => $rule->tenant?->name,
            'category_id' => $hasAdvancedColumns ? $rule->category_id : null,
            'category_name' => $hasAdvancedColumns ? $rule->category?->name : null,
            'effective_commission' => (string) $rule->rate,
            'rate' => (string) $rule->rate,
            'commission_base' => $rule->commission_base,
            'rule_type' => $this->commissionRuleType($rule),
            'promotion_code' => $hasAdvancedColumns ? $rule->promotion_code : null,
            'reason' => $hasAdvancedColumns ? $rule->reason : null,
            'priority' => $hasAdvancedColumns ? (int) $rule->priority : 0,
            'status' => $rule->status,
            'effective_from' => $rule->effective_from?->toIso8601String(),
            'effective_until' => $rule->effective_until?->toIso8601String(),
            'last_updated' => $rule->updated_at?->toIso8601String(),
        ];
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

    private function recordOrderIntervention(MarketplaceAuditLogger $audit, string $action, mixed $actor, MarketplaceOrder $order, array $metadata): void
    {
        foreach ($order->vendorOrders->pluck('tenant_id')->unique() as $tenantId) {
            $audit->record($action, $actor, Tenant::find($tenantId), $order, $metadata + ['affected_business_id' => (int) $tenantId]);
        }
    }

    private function isOrderStatusConsistent(MarketplaceOrder $order, string $status): bool
    {
        $statuses = $order->vendorOrders->pluck('status');
        if ($statuses->isEmpty()) {
            return false;
        }

        return match ($status) {
            'cancelled' => $statuses->every(fn (string $value): bool => $value === 'cancelled'),
            'delivered' => $statuses->every(fn (string $value): bool => $value === 'delivered'),
            'shipped' => $statuses->every(fn (string $value): bool => $value === 'shipped'),
            'partially_shipped' => $statuses->contains(fn (string $value): bool => in_array($value, ['shipped', 'delivered'], true)) && ! $statuses->every(fn (string $value): bool => in_array($value, ['shipped', 'delivered'], true)),
            'processing' => $statuses->contains(fn (string $value): bool => $value === 'processing') && ! $statuses->contains(fn (string $value): bool => in_array($value, ['shipped', 'delivered'], true)),
            'confirmed' => ! $statuses->contains(fn (string $value): bool => in_array($value, ['processing', 'shipped', 'delivered'], true)),
            'pending' => $statuses->every(fn (string $value): bool => $value === 'pending'),
            default => false,
        };
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function percentage(int $numerator, int $denominator): string
    {
        return number_format($denominator > 0 ? ($numerator / $denominator) * 100 : 0, 2, '.', '');
    }

    private function financialCapabilities(?MarketplaceSettingsService $settings = null, ?MarketplaceCommissionService $commissions = null): array
    {
        $settings ??= app(MarketplaceSettingsService::class);
        $commissions ??= app(MarketplaceCommissionService::class);
        $settingsAvailable = $settings->storageAvailable();
        $ledgerAvailable = $commissions->storageAvailable();
        $payoutStorageAvailable = Schema::hasTable('marketplace_payouts');

        return [
            'commission_rules' => ['available' => $settingsAvailable, 'reason' => $settingsAvailable ? 'Commission rates and seller overrides are persisted with effective dates.' : 'The reviewed commission migration is pending.'],
            'settlement_ledger' => ['available' => $ledgerAvailable, 'reason' => $ledgerAvailable ? 'Order-level commission snapshots and seller earnings are persisted.' : 'The reviewed settlement migration is pending.'],
            'payouts' => ['available' => false, 'reason' => $payoutStorageAvailable ? 'Payout records are persisted, but no verified payout provider is configured.' : 'No payout provider or payout records exist yet; the reviewed additive payout migration is pending.'],
            'real_money_refunds' => ['available' => false, 'reason' => 'No verified refund provider is configured.'],
            'disputes' => ['available' => false, 'reason' => 'No dispute workflow or evidence schema exists yet.'],
        ];
    }

    private function moderationActivity(): array
    {
        return MarketplaceAuditLog::query()->with(['actor:id,name', 'tenant:id,name'])->whereIn('action', ['marketplace.store_approval_updated', 'marketplace.store_status_updated', 'marketplace.listing_moderated'])->latest('created_at')->limit(8)->get()->map(fn (MarketplaceAuditLog $log): array => ['id' => $log->id, 'action' => $log->action, 'actor' => $log->actor?->name, 'business' => $log->tenant?->name, 'created_at' => $log->created_at?->toIso8601String(), 'metadata' => $log->metadata ?: []])->values()->all();
    }

    private function listingEligibility(MarketplaceListing $listing): array
    {
        $listing->loadMissing(['store.tenant', 'product']);
        $reasons = [];

        if ($listing->store?->approval_status !== 'approved') {
            $reasons[] = 'The marketplace store is not approved.';
        }
        if (! $listing->store?->is_active) {
            $reasons[] = 'The marketplace store is inactive.';
        }
        if ($listing->store?->tenant?->status !== 'active') {
            $reasons[] = 'The linked business is not active.';
        }
        if ($listing->product?->status !== 'active') {
            $reasons[] = 'The POS product is not active.';
        }
        if (! $listing->product?->variants()->where('status', 'active')->exists()) {
            $reasons[] = 'The product has no active variant.';
        }

        return ['eligible' => $reasons === [], 'reasons' => $reasons];
    }

    private function listingRelations(): array
    {
        return ['store.tenant', 'tenant', 'product.category', 'product.images', 'product.variants.balances.location', 'images'];
    }
}
