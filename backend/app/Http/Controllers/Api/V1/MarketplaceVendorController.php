<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\CollectMarketplaceCodPaymentRequest;
use App\Http\Requests\Marketplace\MarketplaceListingRequest;
use App\Http\Requests\Marketplace\UpdateMarketplaceStoreRequest;
use App\Http\Requests\Marketplace\UploadMarketplaceListingImagesRequest;
use App\Http\Requests\Marketplace\UploadMarketplaceStoreMediaRequest;
use App\Http\Resources\MarketplaceListingResource;
use App\Http\Resources\MarketplaceStoreResource;
use App\Http\Resources\MarketplaceVendorOrderResource;
use App\Http\Resources\MarketplaceVendorSaleResource;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingImage;
use App\Models\MarketplaceOrderCommission;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceRefundRequest;
use App\Models\MarketplaceShipment;
use App\Models\MarketplaceStore;
use App\Models\MarketplaceVendorOrder;
use App\Models\Product;
use App\Services\MarketplaceAuditLogger;
use App\Services\MarketplaceCommissionService;
use App\Services\MarketplaceDocumentService;
use App\Services\MarketplacePaymentService;
use App\Services\MarketplaceSettingsService;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MarketplaceVendorController
{
    public function overview(Request $request, TenantContext $context, MarketplaceCommissionService $commissions): JsonResponse
    {
        $tenant = $context->tenant();
        $store = $this->getOrCreateStore($context);
        $publishedProducts = MarketplaceListing::query()
            ->forTenant($tenant->id)
            ->where('status', 'published')
            ->count();

        $products = Product::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->with(['variants.balances'])
            ->get();
        $lowStockProducts = $products
            ->filter(function (Product $product): bool {
                if (! $product->track_inventory) {
                    return false;
                }

                $variants = $product->variants->where('status', 'active');
                if ($variants->isEmpty()) {
                    return false;
                }

                $available = $variants->sum(fn ($variant): float => $variant->balances->sum(fn ($balance): float => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity));
                $reorderLevel = $variants->max(fn ($variant): float => (float) $variant->reorder_level);

                return $available <= $reorderLevel;
            })
            ->sortBy('name')
            ->take(8)
            ->map(function (Product $product): array {
                $variants = $product->variants->where('status', 'active');
                $available = $variants->sum(fn ($variant): float => $variant->balances->sum(fn ($balance): float => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity));

                return [
                    'id' => $product->uuid,
                    'name' => $product->name,
                    'sku' => $variants->first()?->sku,
                    'available_stock' => $available,
                ];
            })
            ->values()
            ->all();

        $pendingOrders = MarketplaceVendorOrder::where('tenant_id', $tenant->id)->whereIn('status', ['pending', 'processing'])->count();
        $completedOrders = MarketplaceVendorOrder::where('tenant_id', $tenant->id)->whereIn('status', ['delivered'])->count();
        $revenue = MarketplaceVendorOrder::where('tenant_id', $tenant->id)->whereNotIn('status', ['cancelled', 'refunded'])->sum('total');
        $recentOrders = MarketplaceVendorOrder::where('tenant_id', $tenant->id)->with(['store', 'order', 'shipments'])->latest()->limit(8)->get();
        $earnings = $commissions->tenantEarnings($tenant->id);
        $commissionRule = $commissions->effectiveRule($tenant->id);

        return response()->json([
            'success' => true,
            'data' => [
                'store' => (new MarketplaceStoreResource($store))->resolve($request),
                'metrics' => [
                    'published_products' => $publishedProducts,
                    'low_stock_products' => count($lowStockProducts),
                    'pending_orders' => $pendingOrders,
                    'completed_orders' => $completedOrders,
                    'marketplace_revenue' => number_format((float) $revenue, 2, '.', ''),
                ],
                'low_stock_products' => $lowStockProducts,
                'recent_orders' => MarketplaceVendorOrderResource::collection($recentOrders),
                'capabilities' => [
                    'orders' => true,
                    'shipping' => false,
                    'reviews' => false,
                    'earnings' => $earnings['configured'],
                ],
                'earnings' => collect($earnings)->except('transactions')->all(),
                'commission' => [
                    'rate' => $commissionRule['rate'],
                    'source' => $commissionRule['source'],
                    'rule_type' => $commissionRule['rule_type'],
                    'base' => $commissionRule['base'],
                ],
            ],
        ]);
    }

    public function earnings(Request $request, TenantContext $context, MarketplaceCommissionService $commissions): JsonResponse
    {
        $data = $request->validate(['currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/']]);
        $tenantId = $context->tenant()->id;
        $earnings = $commissions->tenantEarnings($tenantId, $data['currency_code'] ?? null);
        $commissionRule = $commissions->effectiveRule($tenantId);

        return response()->json(['success' => true, 'data' => [
            ...$earnings,
            'commission' => [
                'rate' => $commissionRule['rate'],
                'source' => $commissionRule['source'],
                'rule_type' => $commissionRule['rule_type'],
                'base' => $commissionRule['base'],
            ],
        ]]);
    }

    public function orders(Request $request, TenantContext $context): JsonResponse
    {
        $orders = MarketplaceVendorOrder::query()
            ->where('tenant_id', $context->tenant()->id)
            ->with(['store', 'tenant', 'items', 'order.customer', 'order.payments', 'shipments.events'])
            ->when($request->boolean('active_shipping'), function (Builder $query): void {
                // Shipping is an operational queue. Completed shipment records remain
                // queryable through Sales History and are never deleted here.
                $query->where(function (Builder $active): void {
                    $active->whereDoesntHave('shipments')
                        ->orWhereHas('shipments', fn (Builder $shipment) => $shipment->whereNotIn('status', ['delivered', 'completed', 'returned', 'cancelled']));
                });
            })
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('shipment_status'), function (Builder $query) use ($request): void {
                $status = $request->string('shipment_status')->toString();
                if ($status === 'ready') {
                    $query->where(function (Builder $ready): void {
                        $ready->whereDoesntHave('shipments')->orWhereHas('shipments', fn (Builder $shipment) => $shipment->where('status', 'ready'));
                    });
                } else {
                    $query->whereHas('shipments', fn (Builder $shipment) => $shipment->where('status', $status));
                }
            })
            ->when($request->filled('delivery_type'), fn (Builder $query) => $query->whereHas('shipments', fn (Builder $shipment) => $shipment->where('delivery_type', $request->string('delivery_type')->toString())))
            ->when($request->filled('carrier'), fn (Builder $query) => $query->whereHas('shipments', fn (Builder $shipment) => $shipment->where('carrier_name', 'ilike', '%'.$request->string('carrier')->toString().'%')))
            ->when($request->filled('payment_status'), fn (Builder $query) => $query->whereHas('order', fn (Builder $order) => $order->where('payment_status', $request->string('payment_status')->toString())))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(function (Builder $search) use ($term): void {
                    $search->where('vendor_order_number', 'ilike', $term)
                        ->orWhereHas('order', fn (Builder $order) => $order->where('order_number', 'ilike', $term)->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term)));
                    $search->orWhereHas('shipments', fn (Builder $shipment) => $shipment->where('tracking_number', 'ilike', $term)->orWhere('internal_tracking_id', 'ilike', $term));
                });
            })
            ->when($request->filled('from'), fn (Builder $query) => $query->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereDate('created_at', '<=', $request->date('to')))
            ->latest()
            ->paginate(min(max((int) $request->input('per_page', 20), 1), 100));

        return response()->json(['success' => true, 'data' => MarketplaceVendorOrderResource::collection($orders), 'meta' => ['total' => $orders->total(), 'per_page' => $orders->perPage(), 'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()]]);
    }

    public function salesHistory(Request $request, TenantContext $context, MarketplaceCommissionService $commissions): JsonResponse
    {
        $this->validateSalesHistoryRequest($request);
        $query = $this->salesHistoryQuery($request, $context);
        $summary = $this->salesHistorySummary($request, $context, $commissions);
        $sales = $query
            ->with($this->salesHistoryRelations())
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json([
            'success' => true,
            'data' => MarketplaceVendorSaleResource::collection($sales),
            'meta' => [
                'total' => $sales->total(),
                'per_page' => $sales->perPage(),
                'current_page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
                'summary' => $summary,
            ],
        ]);
    }

    public function salesHistoryExport(Request $request, TenantContext $context): StreamedResponse
    {
        $this->validateSalesHistoryRequest($request);
        $query = $this->salesHistoryQuery($request, $context);
        $relations = $this->salesHistoryRelations();

        return response()->streamDownload(function () use ($query, $relations): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Sale Date', 'Vendor Order No.', 'Marketplace Order No.', 'Customer', 'Items', 'Subtotal', 'Discount', 'Tax', 'Shipping', 'Grand Total', 'Currency', 'Payment Method', 'Payment Status', 'Order Status', 'Shipment Status', 'Tracking ID', 'Invoice No.', 'Receipt No.']);
            $query->with($relations)->chunkById(250, function ($sales) use ($handle): void {
                foreach ($sales as $sale) {
                    fputcsv($handle, $this->salesHistoryCsvRow($sale));
                }
            }, 'marketplace_vendor_orders.id');
            fclose($handle);
        }, 'marketplace-sales-history-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function salesHistoryDetail(Request $request, string $order, TenantContext $context): JsonResponse
    {
        $this->validateSalesHistoryRequest($request);
        $sale = $this->salesHistoryQuery($request, $context, false)
            ->where('marketplace_vendor_orders.uuid', $order)
            ->with($this->salesHistoryRelations())
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => new MarketplaceVendorSaleResource($sale)]);
    }

    public function order(Request $request, string $order, TenantContext $context): JsonResponse
    {
        $model = MarketplaceVendorOrder::query()->where('tenant_id', $context->tenant()->id)->where('uuid', $order)->with(['store', 'tenant', 'items.variant', 'order.customer', 'order.payments', 'shipments.events.actor'])->firstOrFail();

        return response()->json(['success' => true, 'data' => new MarketplaceVendorOrderResource($model)]);
    }

    public function invoice(string $order, TenantContext $context, MarketplaceDocumentService $documents): JsonResponse
    {
        $vendorOrder = $this->vendorOrder($order, $context);

        return response()->json(['success' => true, 'data' => $documents->invoice($vendorOrder->order, $vendorOrder)]);
    }

    public function receipt(string $order, TenantContext $context, MarketplaceDocumentService $documents): JsonResponse
    {
        $vendorOrder = $this->vendorOrder($order, $context);

        return response()->json(['success' => true, 'data' => $documents->receipt($vendorOrder->order, $vendorOrder)]);
    }

    public function collectCod(CollectMarketplaceCodPaymentRequest $request, string $order, TenantContext $context, MarketplacePaymentService $payments, MarketplaceAuditLogger $audit): JsonResponse
    {
        $vendorOrder = $this->vendorOrder($order, $context);
        $result = $payments->collectCod($vendorOrder->order, $request->user(), [
            ...$request->validated(),
            'idempotency_key' => $request->header('Idempotency-Key'),
        ], $vendorOrder);
        if (! $result['replayed']) {
            $audit->record('marketplace.cod_payment_collected', $request->user(), $context->tenant(), $vendorOrder, ['order_number' => $vendorOrder->order?->order_number, 'vendor_order_number' => $vendorOrder->vendor_order_number]);
        }

        return response()->json([
            'success' => true,
            'idempotent_replay' => $result['replayed'],
            'data' => new MarketplaceVendorOrderResource($vendorOrder->fresh()->load(['store', 'tenant', 'items.variant', 'order.customer', 'order.payments', 'shipments.events.actor'])),
        ]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $store = $this->getOrCreateStore($context);

        return response()->json(['success' => true, 'data' => new MarketplaceStoreResource($store->load('tenant'))]);
    }

    public function updateStore(UpdateMarketplaceStoreRequest $request, TenantContext $context, MarketplaceAuditLogger $audit): JsonResponse
    {
        $store = $this->getOrCreateStore($context);
        $data = $request->validated();

        if (isset($data['slug']) && MarketplaceStore::query()->where('slug', $data['slug'])->where('id', '!=', $store->getKey())->exists()) {
            throw ValidationException::withMessages(['slug' => ['This marketplace store URL is already in use.']]);
        }

        $store->update($data);
        $audit->record('marketplace.store_updated', $request->user(), $context->tenant(), $store);

        return response()->json(['success' => true, 'data' => new MarketplaceStoreResource($store->refresh()->load('tenant'))]);
    }

    public function uploadStoreMedia(UploadMarketplaceStoreMediaRequest $request, TenantContext $context, MarketplaceAuditLogger $audit): JsonResponse
    {
        $tenant = $context->tenant();
        $store = $this->getOrCreateStore($context);
        $type = $request->string('media_type')->toString();
        $column = $type.'_path';
        $file = $request->file('image');
        $path = $file->storeAs('marketplace/stores/'.$tenant->uuid, Str::uuid().'.'.$file->extension(), 'public');
        $oldPath = $store->{$column};

        try {
            $store->forceFill([$column => $path])->save();
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        $audit->record('marketplace.store_media_updated', $request->user(), $tenant, $store, ['media_type' => $type]);

        return response()->json(['success' => true, 'data' => new MarketplaceStoreResource($store->refresh()->load('tenant'))]);
    }

    public function listings(Request $request, TenantContext $context): JsonResponse
    {
        $listings = MarketplaceListing::query()
            ->forTenant($context->tenant()->id)
            ->with(['store.tenant', 'tenant', 'product.category', 'product.images', 'product.variants.balances', 'images'])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->orderByDesc('marketplace_listings.created_at')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json([
            'success' => true,
            'data' => MarketplaceListingResource::collection($listings),
            'meta' => ['total' => $listings->total(), 'per_page' => $listings->perPage(), 'current_page' => $listings->currentPage(), 'last_page' => $listings->lastPage()],
        ]);
    }

    public function createListing(MarketplaceListingRequest $request, TenantContext $context, MarketplaceAuditLogger $audit): JsonResponse
    {
        $tenant = $context->tenant();
        $store = $this->getOrCreateStore($context);
        $data = $request->validated();
        $product = Product::query()->where('tenant_id', $tenant->id)->where('uuid', $data['product_id'])->first();

        if (! $product) {
            throw ValidationException::withMessages(['product_id' => ['The selected product does not belong to this business.']]);
        }

        if (MarketplaceListing::query()->where('tenant_id', $tenant->id)->where('product_id', $product->id)->exists()) {
            throw ValidationException::withMessages(['product_id' => ['This product is already listed in the marketplace.']]);
        }

        $status = $data['status'] ?? 'draft';
        $this->ensurePublishable($status, $store, $product);
        $slug = $this->uniqueSlug($data['slug'] ?? $product->slug, $product->id);
        $listing = MarketplaceListing::create([
            'tenant_id' => $tenant->id,
            'marketplace_store_id' => $store->id,
            'product_id' => $product->id,
            'slug' => $slug,
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
        $audit->record('marketplace.listing_created', $request->user(), $tenant, $listing, ['product_id' => $product->uuid]);

        return response()->json(['success' => true, 'data' => new MarketplaceListingResource($listing->load($this->listingRelations()))], 201);
    }

    public function updateListing(MarketplaceListingRequest $request, MarketplaceListing $listing, TenantContext $context, MarketplaceAuditLogger $audit): JsonResponse
    {
        abort_unless((int) $listing->tenant_id === (int) $context->tenant()->id, 404);
        $data = $request->validated();
        $product = $listing->product;

        if (isset($data['product_id'])) {
            $product = Product::query()->where('tenant_id', $context->tenant()->id)->where('uuid', $data['product_id'])->first();
            if (! $product) {
                throw ValidationException::withMessages(['product_id' => ['The selected product does not belong to this business.']]);
            }
            $duplicate = MarketplaceListing::query()->where('tenant_id', $context->tenant()->id)->where('product_id', $product->id)->where('id', '!=', $listing->getKey())->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['product_id' => ['This product is already listed in the marketplace.']]);
            }
            $data['product_id'] = $product->id;
        }

        $status = $data['status'] ?? $listing->status;
        $this->ensurePublishable($status, $listing->store, $product);
        if (isset($data['slug']) && MarketplaceListing::query()->where('slug', $data['slug'])->where('id', '!=', $listing->getKey())->exists()) {
            throw ValidationException::withMessages(['slug' => ['This marketplace product URL is already in use.']]);
        }
        $data['published_at'] = $status === 'published' ? ($listing->published_at ?? now()) : null;
        $listing->update($data);
        $audit->record('marketplace.listing_updated', $request->user(), $context->tenant(), $listing);

        return response()->json(['success' => true, 'data' => new MarketplaceListingResource($listing->refresh()->load($this->listingRelations()))]);
    }

    public function uploadListingImages(UploadMarketplaceListingImagesRequest $request, MarketplaceListing $listing, TenantContext $context, MarketplaceAuditLogger $audit): JsonResponse
    {
        abort_unless((int) $listing->tenant_id === (int) $context->tenant()->id, 404);
        $files = $request->file('images', []);
        $nextSortOrder = (int) $listing->images()->max('sort_order') + 1;

        foreach ($files as $index => $file) {
            $path = $file->storeAs('marketplace/stores/'.$context->tenant()->uuid.'/listings/'.$listing->uuid, Str::uuid().'.'.$file->extension(), 'public');
            MarketplaceListingImage::create([
                'tenant_id' => $context->tenant()->id,
                'marketplace_listing_id' => $listing->id,
                'image_path' => $path,
                'sort_order' => $nextSortOrder + $index,
            ]);
        }

        $audit->record('marketplace.listing_images_uploaded', $request->user(), $context->tenant(), $listing, ['count' => count($files)]);

        return response()->json(['success' => true, 'data' => new MarketplaceListingResource($listing->refresh()->load($this->listingRelations()))]);
    }

    private function salesHistoryQuery(Request $request, TenantContext $context, bool $sort = true): Builder
    {
        $query = MarketplaceVendorOrder::query()
            ->where('marketplace_vendor_orders.tenant_id', $context->tenant()->id)
            ->whereHas('order', function (Builder $order): void {
                $order
                    ->whereIn('status', ['confirmed', 'processing', 'partially_shipped', 'shipped', 'delivered', 'cancelled', 'partially_refunded', 'refunded'])
                    ->whereHas('payments', fn (Builder $payment) => $payment->whereIn('status', ['authorized', 'paid', 'refunded']));
            })
            ->when($request->filled('search'), function (Builder $builder) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $builder->where(function (Builder $search) use ($term): void {
                    $search
                        ->where('marketplace_vendor_orders.vendor_order_number', 'ilike', $term)
                        ->orWhereHas('order', function (Builder $order) use ($term): void {
                            $order
                                ->where(function (Builder $orderSearch) use ($term): void {
                                    $orderSearch
                                        ->where('order_number', 'ilike', $term)
                                        ->orWhere('invoice_number', 'ilike', $term);
                                })
                                ->orWhereHas('customer', function (Builder $customer) use ($term): void {
                                    $customer->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term);
                                })
                                ->orWhereHas('payments', function (Builder $payment) use ($term): void {
                                    $payment->where('receipt_number', 'ilike', $term)->orWhere('transaction_reference', 'ilike', $term)->orWhere('provider_reference', 'ilike', $term);
                                });
                        })
                        ->orWhereHas('shipments', function (Builder $shipment) use ($term): void {
                            $shipment->where('internal_tracking_id', 'ilike', $term)->orWhere('tracking_number', 'ilike', $term);
                        });
                });
            })
            ->when($request->filled('date_from'), fn (Builder $builder) => $builder->whereDate('marketplace_vendor_orders.created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn (Builder $builder) => $builder->whereDate('marketplace_vendor_orders.created_at', '<=', $request->date('date_to')))
            ->when($request->filled('payment_method'), fn (Builder $builder) => $builder->whereHas('order.payments', fn (Builder $payment) => $payment->where('method', $request->string('payment_method')->toString())))
            ->when($request->filled('payment_status'), fn (Builder $builder) => $builder->whereHas('order.payments', fn (Builder $payment) => $payment->where('status', $request->string('payment_status')->toString())))
            ->when($request->filled('order_status'), fn (Builder $builder) => $builder->whereHas('order', fn (Builder $order) => $order->where('status', $request->string('order_status')->toString())))
            ->when($request->filled('shipment_status'), function (Builder $builder) use ($request): void {
                $shipmentStatus = $request->string('shipment_status')->toString();
                if ($shipmentStatus === 'ready') {
                    $builder->where(function (Builder $ready) use ($shipmentStatus): void {
                        $ready->whereDoesntHave('shipments')->orWhereHas('shipments', fn (Builder $shipment) => $shipment->where('status', $shipmentStatus));
                    });
                } else {
                    $builder->whereHas('shipments', fn (Builder $shipment) => $shipment->where('status', $shipmentStatus));
                }
            })
            ->when($request->filled('min_amount'), fn (Builder $builder) => $builder->where('marketplace_vendor_orders.total', '>=', $request->input('min_amount')))
            ->when($request->filled('max_amount'), fn (Builder $builder) => $builder->where('marketplace_vendor_orders.total', '<=', $request->input('max_amount')));

        if (! $sort) {
            return $query;
        }

        return match ($request->string('sort', 'newest')->toString()) {
            'oldest' => $query->orderBy('marketplace_vendor_orders.created_at')->orderBy('marketplace_vendor_orders.id'),
            'highest_total' => $query->orderByDesc('marketplace_vendor_orders.total')->orderByDesc('marketplace_vendor_orders.id'),
            'lowest_total' => $query->orderBy('marketplace_vendor_orders.total')->orderBy('marketplace_vendor_orders.id'),
            'latest_payment' => $query->orderByDesc(MarketplacePayment::query()->select('paid_at')->whereColumn('marketplace_payments.marketplace_order_id', 'marketplace_vendor_orders.marketplace_order_id')->latest('id')->limit(1))->orderByDesc('marketplace_vendor_orders.id'),
            'latest_delivery' => $query->orderByDesc(MarketplaceShipment::query()->select('delivered_at')->whereColumn('marketplace_shipments.marketplace_vendor_order_id', 'marketplace_vendor_orders.id')->latest('id')->limit(1))->orderByDesc('marketplace_vendor_orders.id'),
            default => $query
                ->orderByRaw("CASE WHEN marketplace_vendor_orders.status = 'delivered' OR EXISTS (SELECT 1 FROM marketplace_shipments prioritized_shipments WHERE prioritized_shipments.marketplace_vendor_order_id = marketplace_vendor_orders.id AND prioritized_shipments.status IN ('delivered', 'completed')) THEN 0 ELSE 1 END")
                ->orderByDesc('marketplace_vendor_orders.created_at')
                ->orderByDesc('marketplace_vendor_orders.id'),
        };
    }

    private function salesHistorySummary(Request $request, TenantContext $context, MarketplaceCommissionService $commissions): array
    {
        $query = $this->salesHistoryQuery($request, $context, false);
        $totals = (clone $query)->selectRaw('COUNT(*) AS sales_count, COALESCE(SUM(marketplace_vendor_orders.total), 0) AS gross_revenue, COALESCE(SUM(marketplace_vendor_orders.discount_total), 0) AS discount_total, COALESCE(SUM(marketplace_vendor_orders.tax_total), 0) AS tax_total, COALESCE(SUM(marketplace_vendor_orders.shipping_total), 0) AS shipping_revenue')->first();
        $orderIds = (clone $query)->select('marketplace_vendor_orders.marketplace_order_id')->distinct()->pluck('marketplace_order_id');
        $vendorOrderIds = (clone $query)->select('marketplace_vendor_orders.id')->pluck('id');
        $refundTotal = $orderIds->isEmpty() ? 0 : MarketplaceRefundRequest::query()->whereIn('marketplace_order_id', $orderIds)->where('status', 'approved')->sum('approved_amount');
        $commissionConfigured = $commissions->storageAvailable();
        $commissionTotal = $commissionConfigured && $vendorOrderIds->isNotEmpty() ? MarketplaceOrderCommission::query()->whereIn('marketplace_vendor_order_id', $vendorOrderIds)->sum('commission_amount') : 0;
        $vendorEarnings = $commissionConfigured && $vendorOrderIds->isNotEmpty() ? MarketplaceOrderCommission::query()->whereIn('marketplace_vendor_order_id', $vendorOrderIds)->sum('seller_net_amount') : 0;
        $grossRevenue = (float) ($totals?->gross_revenue ?? 0);
        $refunded = (float) $refundTotal;

        return [
            'sales_count' => (int) ($totals?->sales_count ?? 0),
            'gross_revenue' => $this->money((string) $grossRevenue),
            'total_revenue' => $this->money((string) $grossRevenue),
            'discount_total' => $this->money((string) ($totals?->discount_total ?? 0)),
            'tax_total' => $this->money((string) ($totals?->tax_total ?? 0)),
            'shipping_revenue' => $this->money((string) ($totals?->shipping_revenue ?? 0)),
            'paid_orders' => (int) (clone $query)->whereHas('order.payments', fn (Builder $payment) => $payment->where('status', 'paid'))->count(),
            'delivered_orders' => (int) (clone $query)->where('marketplace_vendor_orders.status', 'delivered')->count(),
            'refunded_amount' => $this->money((string) $refunded),
            'net_revenue' => $this->money((string) ($grossRevenue - $refunded)),
            'commission_configured' => $commissionConfigured,
            'marketplace_commission' => $this->money((string) $commissionTotal),
            'vendor_earnings' => $this->money((string) $vendorEarnings),
        ];
    }

    private function salesHistoryRelations(): array
    {
        return [
            'store',
            'tenant',
            'items.variant',
            'order.customer.customerProfile',
            'order.payments.receivedBy',
            'order.events',
            'order.refundRequests',
            'shipments.events.actor',
            'commission',
        ];
    }

    private function salesHistoryCsvRow(MarketplaceVendorOrder $sale): array
    {
        $order = $sale->order;
        $payment = $order?->payments?->sortByDesc('id')->first();
        $shipment = $sale->shipments?->sortByDesc('id')->first();
        $shipmentStatus = $shipment?->status ?: ($sale->status === 'delivered' ? 'delivered' : 'ready');

        return [
            $sale->created_at?->toIso8601String(),
            $sale->vendor_order_number,
            $order?->order_number,
            $order?->customer?->name,
            $sale->items?->sum('quantity') ?? 0,
            $sale->subtotal,
            $sale->discount_total,
            $sale->tax_total,
            $sale->shipping_total,
            $sale->total,
            $sale->currency_code,
            $payment?->method,
            $payment?->status ?: $order?->payment_status,
            $order?->status,
            $shipmentStatus,
            $shipment?->internal_tracking_id ?: $shipment?->tracking_number,
            $order?->invoice_number,
            $payment?->receipt_number,
        ];
    }

    private function validateSalesHistoryRequest(Request $request): void
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'payment_method' => ['nullable', 'string', 'in:cod,online,card,qr,bank_transfer'],
            'payment_status' => ['nullable', 'string', 'in:authorized,paid,refunded,pending,failed,cancelled'],
            'order_status' => ['nullable', 'string', 'in:confirmed,processing,partially_shipped,shipped,delivered,cancelled,partially_refunded,refunded'],
            'shipment_status' => ['nullable', 'string', 'in:ready,shipped,in_transit,delivered,delivery_failed,returned,cancelled'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'gte:min_amount'],
            'sort' => ['nullable', 'string', 'in:newest,oldest,highest_total,lowest_total,latest_payment,latest_delivery'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function getOrCreateStore(TenantContext $context): MarketplaceStore
    {
        $tenant = $context->tenant();
        $store = MarketplaceStore::query()->where('tenant_id', $tenant->id)->first();
        if (! $store) {
            $settings = app(MarketplaceSettingsService::class);
            if (! $settings->sellerRegistrationEnabled()) {
                throw new UnprocessableEntityHttpException('New marketplace seller registration is currently closed.');
            }
            $store = MarketplaceStore::create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->display_name ?: $tenant->name,
                'slug' => $this->uniqueStoreSlug($tenant->slug ?: $tenant->name),
                'description' => $tenant->description,
                'approval_status' => $tenant->status === 'active' ? 'approved' : 'pending',
                'approved_at' => $tenant->status === 'active' ? now() : null,
                'is_active' => true,
            ]);
        }

        return $store->load('tenant');
    }

    private function vendorOrder(string $order, TenantContext $context): MarketplaceVendorOrder
    {
        return MarketplaceVendorOrder::query()
            ->where('tenant_id', $context->tenant()->id)
            ->where('uuid', $order)
            ->with(['store.tenant', 'tenant', 'items.variant', 'order.customer', 'order.payments', 'shipments.events.actor'])
            ->firstOrFail();
    }

    private function ensurePublishable(string $status, MarketplaceStore $store, Product $product): void
    {
        if ($status !== 'published') {
            return;
        }
        if (! $store->is_active || $store->tenant?->status !== 'active') {
            throw ValidationException::withMessages(['status' => ['The marketplace store must be active before publishing products.']]);
        }
        if ($product->status !== 'active' || ! $product->variants()->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['status' => ['Only active products with an active variant can be published.']]);
        }
    }

    private function uniqueStoreSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'store';
        $slug = $base;
        $suffix = 2;
        while (MarketplaceStore::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function uniqueSlug(string $value, int $productId): string
    {
        $base = Str::slug($value) ?: 'product';
        $slug = $base;
        $suffix = 2;
        while (MarketplaceListing::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function listingRelations(): array
    {
        return ['store.tenant', 'tenant', 'product.category', 'product.images', 'product.variants.balances', 'images'];
    }
}
