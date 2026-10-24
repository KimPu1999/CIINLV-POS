<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\CollectMarketplaceCodPaymentRequest;
use App\Http\Requests\Marketplace\MarketplaceListingRequest;
use App\Http\Requests\Marketplace\UpdateMarketplaceStoreRequest;
use App\Http\Requests\Marketplace\UploadMarketplaceListingImagesRequest;
use App\Http\Requests\Marketplace\UploadMarketplaceStoreMediaRequest;
use App\Http\Resources\MarketplaceListingResource;
use App\Http\Resources\MarketplaceProductResource;
use App\Http\Resources\MarketplaceStoreResource;
use App\Http\Resources\MarketplaceVendorOrderResource;
use App\Http\Resources\MarketplaceVendorSaleResource;
use App\Http\Resources\StoreResource;
use App\Models\InventoryBalance;
use App\Models\MarketplaceAuditLog;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingImage;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderCommission;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceRefundRequest;
use App\Models\MarketplaceShipment;
use App\Models\MarketplaceStore;
use App\Models\MarketplaceVendorOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\MarketplaceAuditLogger;
use App\Services\MarketplaceCommissionService;
use App\Services\MarketplaceDocumentService;
use App\Services\MarketplacePaymentService;
use App\Services\MarketplaceSettingsService;
use App\Services\PosAccessService;
use App\Services\ReportScopeService;
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
        $data = $request->validate([
            'branch_id' => ['nullable', 'string', 'max:80'],
            'currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'search' => ['nullable', 'string', 'max:120'],
            'entry_type' => ['nullable', 'in:platform_commission,seller_earning,refund,payout,adjustment'],
            'direction' => ['nullable', 'in:credit,debit'],
            'status' => ['nullable', 'in:pending,available,reversed'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', 'in:created_at,amount,entry_type'],
            'direction_sort' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
        ]);
        $tenantId = $context->tenant()->id;
        if (! empty($data['branch_id']) && $data['branch_id'] !== '__unassigned__') {
            $authorized = app(ReportScopeService::class)->storeIds($request->user(), $context->tenant(), $data['branch_id']);
            abort_if($authorized->isEmpty(), 403, 'You are not authorized to view earnings for this branch.');
        }
        $currency = $data['currency_code'] ?? $context->tenant()->businessSetting?->currency_code;
        $data['timezone'] = $context->tenant()->businessSetting?->timezone ?: config('app.timezone');
        $earnings = $commissions->tenantEarnings($tenantId, $currency, $data);
        $commissionRule = $commissions->effectiveRule($tenantId);

        return response()->json(['success' => true, 'data' => [
            ...$earnings,
            'currency_code' => $currency,
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
        $branchScope = $this->salesHistoryBranchScope($request, $context);
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
                'can_view_unassigned' => $branchScope['include_unassigned'],
            ],
        ]);
    }

    public function deliveryHistory(Request $request, TenantContext $context, MarketplaceCommissionService $commissions): JsonResponse
    {
        $this->validateSalesHistoryRequest($request);
        $branchScope = $this->salesHistoryBranchScope($request, $context);
        $query = $this->salesHistoryQuery($request, $context);
        $query->whereHas('shipments', fn (Builder $shipment) => $shipment->whereIn('status', ['delivered', 'completed']));
        $summary = $this->salesHistorySummary($request, $context, $commissions, true);
        $deliveries = $query
            ->with($this->salesHistoryRelations())
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json([
            'success' => true,
            'data' => MarketplaceVendorSaleResource::collection($deliveries),
            'meta' => [
                'total' => $deliveries->total(),
                'per_page' => $deliveries->perPage(),
                'current_page' => $deliveries->currentPage(),
                'last_page' => $deliveries->lastPage(),
                'summary' => $summary,
                'can_view_unassigned' => $branchScope['include_unassigned'],
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
            fputcsv($handle, ['Sale Date', 'Vendor Order No.', 'Marketplace Order No.', 'Customer', 'Branch', 'Fulfillment Locations', 'Items', 'Subtotal', 'Discount', 'Tax', 'Shipping', 'Grand Total', 'Currency', 'Payment Method', 'Payment Status', 'Order Status', 'Shipment Status', 'Tracking ID', 'Invoice No.', 'Receipt No.']);
            $query->with($relations)->chunkById(250, function ($sales) use ($handle): void {
                foreach ($sales as $sale) {
                    fputcsv($handle, array_map(fn ($value) => $this->csvSafe($value), $this->salesHistoryCsvRow($sale)));
                }
            }, 'marketplace_vendor_orders.id', 'id');
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

        return response()->json(['success' => true, 'data' => $this->storeManagementPayload($request, $store)]);
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

        return response()->json(['success' => true, 'data' => $this->storeManagementPayload($request, $store->refresh())]);
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

        return response()->json(['success' => true, 'data' => $this->storeManagementPayload($request, $store->refresh())]);
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

    public function products(Request $request, TenantContext $context, ReportScopeService $scope): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'uuid'],
            'brand' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'uuid'],
            'listing_status' => ['nullable', 'in:published,draft,archived,not_listed'],
            'stock_status' => ['nullable', 'in:in_stock,low_stock,out_of_stock'],
            'sort' => ['nullable', 'in:name,created_at,selling_price,available_stock'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $tenant = $context->tenant();
        $requestedBranch = $request->filled('branch_id') ? (string) $request->input('branch_id') : null;
        $storeIds = $scope->storeIds($request->user(), $tenant, $requestedBranch);
        abort_if($requestedBranch !== null && $storeIds->isEmpty(), 403, 'The selected branch is not authorized.');
        $locationIds = $scope->locationIds($request->user(), $tenant, $requestedBranch);

        $query = Product::query()
            ->where('products.tenant_id', $tenant->id)
            ->when($requestedBranch !== null, function (Builder $builder) use ($locationIds): void {
                $builder->where(function (Builder $branchProducts) use ($locationIds): void {
                    $branchProducts
                        ->where('products.track_inventory', false)
                        ->orWhereHas('variants.balances', fn (Builder $balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locationIds));
                });
            })
            ->when($request->filled('search'), function (Builder $builder) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $builder->where(function (Builder $search) use ($term): void {
                    $search->where('products.name', 'ilike', $term)
                        ->orWhere('products.brand', 'ilike', $term)
                        ->orWhereHas('variants', fn (Builder $variant) => $variant->where('product_variants.sku', 'ilike', $term)->orWhere('product_variants.barcode', 'ilike', $term));
                });
            })
            ->when($request->filled('category_id'), fn (Builder $builder) => $builder->whereHas('category', fn (Builder $category) => $category->where('categories.uuid', $request->string('category_id')->toString())))
            ->when($request->filled('brand'), fn (Builder $builder) => $builder->where('products.brand', 'ilike', '%'.$request->string('brand')->toString().'%'))
            ->when($request->input('listing_status') === 'published', fn (Builder $builder) => $builder->whereHas('marketplaceListings', fn (Builder $listing) => $listing->where('status', 'published')))
            ->when($request->input('listing_status') === 'draft', fn (Builder $builder) => $builder->whereHas('marketplaceListings', fn (Builder $listing) => $listing->where('status', 'draft')))
            ->when($request->input('listing_status') === 'archived', fn (Builder $builder) => $builder->whereHas('marketplaceListings', fn (Builder $listing) => $listing->where('status', 'archived')))
            ->when($request->input('listing_status') === 'not_listed', fn (Builder $builder) => $builder->whereDoesntHave('marketplaceListings'));

        if ($request->input('stock_status') === 'out_of_stock') {
            $query->where('products.track_inventory', true)->whereDoesntHave('variants', fn (Builder $variant) => $variant->where('status', 'active')->whereHas('balances', fn (Builder $balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locationIds)->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0')));
        } elseif ($request->input('stock_status') === 'low_stock') {
            $query->where('products.track_inventory', true)->whereHas('variants', fn (Builder $variant) => $variant->where('status', 'active')->whereHas('balances', fn (Builder $balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locationIds)->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0')->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level')));
        } elseif ($request->input('stock_status') === 'in_stock') {
            $query->where('products.track_inventory', true)->whereHas('variants', fn (Builder $variant) => $variant->where('status', 'active')->whereHas('balances', fn (Builder $balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locationIds)->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0')));
        }

        $stockSubquery = InventoryBalance::query()
            ->selectRaw('COALESCE(SUM(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity), 0)')
            ->join('product_variants', function ($join): void {
                $join->on('product_variants.id', '=', 'inventory_balances.product_variant_id')->on('product_variants.tenant_id', '=', 'inventory_balances.tenant_id');
            })
            ->where('inventory_balances.tenant_id', $tenant->id)
            ->whereIn('inventory_balances.inventory_location_id', $locationIds)
            ->whereColumn('product_variants.product_id', 'products.id');
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';

        if ($sort === 'selling_price') {
            $query->orderBy(ProductVariant::query()->select('selling_price')->whereColumn('product_variants.product_id', 'products.id')->where('status', 'active')->orderByDesc('is_default')->orderBy('id')->limit(1), $direction);
        } elseif ($sort === 'available_stock') {
            $query->orderBy($stockSubquery, $direction);
        } else {
            $query->orderBy('products.'.$sort, $direction);
        }

        $total = (clone $query)->count('products.id');
        $published = (clone $query)->whereHas('marketplaceListings', fn (Builder $listing) => $listing->where('status', 'published'))->count('products.id');
        $lowStock = (clone $query)->where('products.track_inventory', true)->whereHas('variants', fn (Builder $variant) => $variant->where('status', 'active')->whereHas('balances', function (Builder $balance) use ($locationIds): void {
            $balance->whereIn('inventory_balances.inventory_location_id', $locationIds)
                ->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0')
                ->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level');
        }))->count('products.id');
        $products = $query
            ->with([
                'category',
                'images',
                'marketplaceListings.images',
                'variants' => fn ($variant) => $variant->where('status', 'active')->with(['balances' => fn ($balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locationIds)]),
            ])
            ->orderBy('products.id')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json([
            'success' => true,
            'data' => MarketplaceProductResource::collection($products),
            'meta' => [
                'total' => $total,
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'summary' => [
                    'total_products' => $total,
                    'published_products' => $published,
                    'unpublished_products' => max(0, $total - $published),
                    'low_stock_products' => $lowStock,
                ],
            ],
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

    private function salesHistoryBranchScope(Request $request, TenantContext $context): array
    {
        $cached = $request->attributes->get('marketplace_sales_history_branch_scope');
        if (is_array($cached)) {
            return $cached;
        }

        $tenant = $context->tenant();
        $requested = $request->filled('branch_id') ? (string) $request->input('branch_id') : null;
        $reportScope = app(ReportScopeService::class);
        $authorizedStoreIds = $reportScope->storeIds($request->user(), $tenant);
        $role = app(PosAccessService::class)->role($request->user(), (int) $tenant->id);
        $platformScoped = $request->user()->isSuperAdmin() && $context->allowsPlatformAccessTo($tenant);
        $canViewUnassigned = $role === 'business_admin' || $platformScoped;

        if ($requested === 'unassigned') {
            abort_unless($canViewUnassigned, 403, 'Unassigned marketplace sales are not available for this user.');
            $selectedStoreIds = collect();
        } elseif ($requested !== null) {
            abort_unless(Str::isUuid($requested), 422, 'The selected branch is invalid.');
            $selectedStoreIds = $reportScope->storeIds($request->user(), $tenant, $requested);
            abort_if($selectedStoreIds->isEmpty(), 403, 'The selected branch is not authorized.');
        } else {
            $selectedStoreIds = $authorizedStoreIds;
        }

        $scope = [
            'requested' => $requested,
            'store_ids' => $selectedStoreIds,
            'authorized_store_ids' => $authorizedStoreIds,
            'selected_store_id' => $requested !== null && $requested !== 'unassigned' ? (int) $selectedStoreIds->first() : null,
            'include_unassigned' => $requested === 'unassigned' || ($requested === null && $canViewUnassigned),
        ];
        $request->attributes->set('marketplace_sales_history_branch_scope', $scope);
        $request->attributes->set('marketplace_sales_history_authorized_branch_ids', $authorizedStoreIds->all());
        $request->attributes->set('marketplace_sales_history_can_view_unassigned', $canViewUnassigned);

        return $scope;
    }

    private function applySalesHistoryBranchScope(Builder $query, Request $request, TenantContext $context): void
    {
        $scope = $this->salesHistoryBranchScope($request, $context);
        $locationRelation = 'items.reservations.balance.location';

        if ($scope['requested'] === 'unassigned') {
            $query->whereDoesntHave($locationRelation, fn (Builder $location) => $location->whereNotNull('inventory_locations.store_id'));

            return;
        }

        if ($scope['requested'] !== null) {
            $query->whereHas($locationRelation, fn (Builder $location) => $location->whereIn('inventory_locations.store_id', $scope['store_ids']->all()));

            return;
        }

        if ($scope['store_ids']->isEmpty() && ! $scope['include_unassigned']) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $branch) use ($scope, $locationRelation): void {
            if ($scope['store_ids']->isNotEmpty()) {
                $branch->whereHas($locationRelation, fn (Builder $location) => $location->whereIn('inventory_locations.store_id', $scope['store_ids']->all()));
            }
            if ($scope['include_unassigned']) {
                $branch->orWhereDoesntHave($locationRelation, fn (Builder $location) => $location->whereNotNull('inventory_locations.store_id'));
            }
        });
    }

    private function branchAllocatedSalesHistorySummary(Builder $query, array $scope, MarketplaceCommissionService $commissions): array
    {
        $selectedKey = $scope['requested'] === 'unassigned' ? 'unassigned' : (string) $scope['selected_store_id'];
        $commissionConfigured = $commissions->storageAvailable();
        $totals = [
            'sales_count' => 0,
            'gross_revenue' => 0.0,
            'discount_total' => 0.0,
            'tax_total' => 0.0,
            'shipping_revenue' => 0.0,
            'paid_orders' => 0,
            'delivered_orders' => 0,
            'refunded_amount' => 0.0,
            'marketplace_commission' => 0.0,
            'vendor_earnings' => 0.0,
        ];

        $query
            ->with([
                'items.reservations.balance.location.store',
                'order.payments',
                'order.refundRequests',
                'shipments',
                'commission',
            ])
            ->chunkById(250, function ($sales) use (&$totals, $selectedKey, $commissionConfigured): void {
                foreach ($sales as $sale) {
                    $totals['sales_count']++;
                    $weight = $this->salesHistoryAttributionWeights($sale)[$selectedKey] ?? 0.0;
                    $totals['gross_revenue'] += (float) $sale->total * $weight;
                    $totals['discount_total'] += (float) $sale->discount_total * $weight;
                    $totals['tax_total'] += (float) $sale->tax_total * $weight;
                    $totals['shipping_revenue'] += (float) $sale->shipping_total * $weight;

                    if ($sale->order?->payments?->contains(fn (MarketplacePayment $payment): bool => $payment->status === 'paid')) {
                        $totals['paid_orders']++;
                    }
                    if ($sale->status === 'delivered') {
                        $totals['delivered_orders']++;
                    }

                    $totals['refunded_amount'] += (float) ($sale->order?->refundRequests?->where('status', 'approved')->sum('approved_amount') ?? 0) * $weight;
                    if ($commissionConfigured && $sale->commission) {
                        $totals['marketplace_commission'] += (float) $sale->commission->commission_amount * $weight;
                        $totals['vendor_earnings'] += (float) $sale->commission->seller_net_amount * $weight;
                    }
                }
            }, 'marketplace_vendor_orders.id', 'id');

        return [
            'sales_count' => $totals['sales_count'],
            'gross_revenue' => $this->money((string) $totals['gross_revenue']),
            'total_revenue' => $this->money((string) $totals['gross_revenue']),
            'discount_total' => $this->money((string) $totals['discount_total']),
            'tax_total' => $this->money((string) $totals['tax_total']),
            'shipping_revenue' => $this->money((string) $totals['shipping_revenue']),
            'paid_orders' => $totals['paid_orders'],
            'delivered_orders' => $totals['delivered_orders'],
            'refunded_amount' => $this->money((string) $totals['refunded_amount']),
            'net_revenue' => $this->money((string) ($totals['gross_revenue'] - $totals['refunded_amount'])),
            'commission_configured' => $commissionConfigured,
            'marketplace_commission' => $this->money((string) $totals['marketplace_commission']),
            'vendor_earnings' => $this->money((string) $totals['vendor_earnings']),
            'allocation_method' => 'line_value_prorated_by_fulfillment_reservation',
        ];
    }

    private function salesHistoryAttributionWeights(MarketplaceVendorOrder $sale): array
    {
        $values = [];
        foreach ($sale->items ?? collect() as $item) {
            $lineTotal = max((float) $item->line_total, 0);
            $quantity = max((float) $item->quantity, 0);
            $branchQuantities = [];

            foreach ($item->reservations ?? collect() as $reservation) {
                $key = $reservation->balance?->location?->store_id === null
                    ? 'unassigned'
                    : (string) $reservation->balance->location->store_id;
                $branchQuantities[$key] = ($branchQuantities[$key] ?? 0) + max((float) $reservation->quantity, 0);
            }

            if ($lineTotal <= 0 || $quantity <= 0 || $branchQuantities === []) {
                $values['unassigned'] = ($values['unassigned'] ?? 0) + $lineTotal;

                continue;
            }

            $coveredQuantity = array_sum($branchQuantities);
            $denominator = max($coveredQuantity, $quantity);
            foreach ($branchQuantities as $key => $branchQuantity) {
                $values[$key] = ($values[$key] ?? 0) + ($lineTotal * $branchQuantity / $denominator);
            }
            if ($coveredQuantity < $quantity) {
                $values['unassigned'] = ($values['unassigned'] ?? 0) + ($lineTotal * ($quantity - $coveredQuantity) / $quantity);
            }
        }

        $total = array_sum($values);
        if ($total <= 0) {
            return ['unassigned' => 1.0];
        }

        return array_map(static fn (float $value): float => $value / $total, $values);
    }

    private function salesHistoryQuery(Request $request, TenantContext $context, bool $sort = true): Builder
    {
        $query = MarketplaceVendorOrder::query()
            ->where('marketplace_vendor_orders.tenant_id', $context->tenant()->id)
            ->where(function (Builder $history): void {
                $history->whereHas('order', function (Builder $order): void {
                    $order
                        ->whereIn('status', ['confirmed', 'processing', 'partially_shipped', 'shipped', 'delivered', 'cancelled', 'partially_refunded', 'refunded'])
                        ->whereHas('payments', fn (Builder $payment) => $payment->whereIn('status', ['authorized', 'paid', 'refunded']));
                })->orWhere(function (Builder $delivered): void {
                    $delivered
                        ->where('marketplace_vendor_orders.status', 'delivered')
                        ->whereHas('shipments', fn (Builder $shipment) => $shipment->whereIn('status', ['delivered', 'completed']))
                        ->whereHas('order', fn (Builder $order) => $order->whereIn('status', ['confirmed', 'processing', 'partially_shipped', 'shipped', 'delivered']));
                });
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

        $this->applySalesHistoryBranchScope($query, $request, $context);

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

    private function salesHistorySummary(Request $request, TenantContext $context, MarketplaceCommissionService $commissions, bool $deliveriesOnly = false): array
    {
        $branchScope = $this->salesHistoryBranchScope($request, $context);
        $query = $this->salesHistoryQuery($request, $context, false);
        if ($deliveriesOnly) {
            $query->whereHas('shipments', fn (Builder $shipment) => $shipment->whereIn('status', ['delivered', 'completed']));
        } else {
            // Pending COD deliveries are visible in history but must not inflate paid totals.
            $query->whereHas('order.payments', fn (Builder $payment) => $payment->whereIn('status', ['authorized', 'paid', 'refunded']));
        }
        $financialQuery = clone $query;
        if ($deliveriesOnly) {
            $financialQuery->whereHas('order.payments', fn (Builder $payment) => $payment->whereIn('status', ['authorized', 'paid', 'refunded']));
        }
        if ($branchScope['requested'] !== null) {
            return $this->branchAllocatedSalesHistorySummary($financialQuery, $branchScope, $commissions);
        }
        $totals = (clone $financialQuery)->selectRaw('COUNT(*) AS sales_count, COALESCE(SUM(marketplace_vendor_orders.total), 0) AS gross_revenue, COALESCE(SUM(marketplace_vendor_orders.discount_total), 0) AS discount_total, COALESCE(SUM(marketplace_vendor_orders.tax_total), 0) AS tax_total, COALESCE(SUM(marketplace_vendor_orders.shipping_total), 0) AS shipping_revenue')->first();
        $orderIds = (clone $financialQuery)->select('marketplace_vendor_orders.marketplace_order_id')->distinct()->pluck('marketplace_order_id');
        $vendorOrderIds = (clone $financialQuery)->select('marketplace_vendor_orders.id')->pluck('id');
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
            'items.reservations.balance.location.store',
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
        $attribution = $sale->branchAttributionData();

        return [
            $sale->created_at?->toIso8601String(),
            $sale->vendor_order_number,
            $order?->order_number,
            $order?->customer?->name,
            $attribution['label'],
            collect($attribution['locations'])->pluck('name')->implode(' | '),
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
            'branch_id' => ['nullable', 'string', 'max:36'],
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

    private function csvSafe(mixed $value): mixed
    {
        $value = is_scalar($value) || $value === null ? (string) $value : json_encode($value);

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    private function storeManagementPayload(Request $request, MarketplaceStore $store): array
    {
        $store->load(['tenant.businessSetting']);
        $tenant = $store->tenant;
        $branches = $tenant->stores()->orderByDesc('is_main')->orderBy('name')->get();
        $latestActivity = MarketplaceAuditLog::query()
            ->with('actor:id,name,email')
            ->where('tenant_id', $tenant->id)
            ->whereIn('action', [
                'marketplace.store_updated',
                'marketplace.store_media_updated',
                'marketplace.store_status_updated',
                'marketplace.store_approval_updated',
            ])
            ->latest('created_at')
            ->limit(8)
            ->get();
        $currency = $tenant->businessSetting?->currency_code ?: 'MMK';
        $vendorOrders = MarketplaceVendorOrder::query()->where('tenant_id', $tenant->id);
        $totalSales = (clone $vendorOrders)->whereNotIn('status', ['cancelled', 'refunded'])->sum('total');
        $totalCustomers = MarketplaceOrder::query()
            ->whereNotNull('customer_user_id')
            ->whereHas('vendorOrders', fn (Builder $query) => $query->where('tenant_id', $tenant->id))
            ->distinct('customer_user_id')
            ->count('customer_user_id');
        $settings = $tenant->businessSetting;

        return [
            ...(new MarketplaceStoreResource($store))->resolve($request),
            'business' => [
                'name' => $tenant->name,
                'display_name' => $tenant->display_name,
                'business_type' => $tenant->business_type,
                'business_code' => $tenant->business_code,
                'company_registration_number' => $tenant->company_registration_number,
                'personal_registration_number' => $tenant->personal_registration_number,
                'tax_registration_number' => $tenant->tax_registration_number,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'website' => $tenant->website,
                'address' => $tenant->address,
                'city' => $tenant->city,
                'state' => $tenant->state,
                'postal_code' => $tenant->postal_code,
                'country' => $tenant->country,
                'status' => $tenant->status,
                'approval_status' => $tenant->approval_status,
                'verification_status' => $tenant->verification_status,
                'created_at' => $tenant->created_at,
            ],
            'public_url' => rtrim((string) config('app.frontend_url'), '/').'/marketplace/stores/'.rawurlencode((string) $store->slug),
            'summary' => [
                'total_products' => (int) $tenant->products()->where('status', 'active')->count(),
                'total_orders' => (int) (clone $vendorOrders)->count(),
                'total_customers' => (int) $totalCustomers,
                'total_sales' => number_format((float) $totalSales, 2, '.', ''),
                'currency_code' => $currency,
                'active_branches' => (int) $branches->where('status', 'active')->count(),
                'published_marketplace_products' => (int) $store->listings()->where('status', 'published')->count(),
            ],
            'business_settings' => [
                'currency_code' => $currency,
                'timezone' => $settings?->timezone ?: config('app.timezone'),
                'locale' => $settings?->locale ?: 'en',
                'language' => data_get($settings?->receipt_configuration, 'language', $settings?->locale ?: 'en'),
            ],
            'branches' => $branches->map(fn ($branch): array => (new StoreResource($branch))->resolve($request))->values()->all(),
            'activity' => $latestActivity->map(fn (MarketplaceAuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->name,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
            'updated_by' => $latestActivity->first()?->actor?->name,
        ];
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
