<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\MarketplaceListingRequest;
use App\Http\Requests\Marketplace\UploadMarketplaceListingImagesRequest;
use App\Http\Requests\Marketplace\UploadMarketplaceStoreMediaRequest;
use App\Http\Requests\Marketplace\UpdateMarketplaceStoreRequest;
use App\Http\Resources\MarketplaceListingResource;
use App\Http\Resources\MarketplaceStoreResource;
use App\Http\Resources\MarketplaceVendorOrderResource;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingImage;
use App\Models\MarketplaceStore;
use App\Models\MarketplaceVendorOrder;
use App\Models\Product;
use App\Services\MarketplaceAuditLogger;
use App\Services\MarketplaceSettingsService;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketplaceVendorController
{
    public function overview(Request $request, TenantContext $context): JsonResponse
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
                    'earnings' => false,
                ],
            ],
        ]);
    }

    public function orders(Request $request, TenantContext $context): JsonResponse
    {
        $orders = MarketplaceVendorOrder::query()
            ->where('tenant_id', $context->tenant()->id)
            ->with(['store', 'tenant', 'items', 'order', 'shipments.events'])
            ->latest()
            ->paginate(min(max((int) $request->input('per_page', 20), 1), 50));

        return response()->json(['success' => true, 'data' => MarketplaceVendorOrderResource::collection($orders), 'meta' => ['total' => $orders->total(), 'per_page' => $orders->perPage(), 'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()]]);
    }

    public function order(Request $request, string $order, TenantContext $context): JsonResponse
    {
        $model = MarketplaceVendorOrder::query()->where('tenant_id', $context->tenant()->id)->where('uuid', $order)->with(['store', 'tenant', 'items', 'order.payments', 'shipments.events.actor'])->firstOrFail();

        return response()->json(['success' => true, 'data' => new MarketplaceVendorOrderResource($model)]);
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
        $requiresApproval = app(MarketplaceSettingsService::class)->listingApprovalRequired();
        $submittedForApproval = $status === 'published' && $requiresApproval;
        if ($submittedForApproval) {
            $status = 'draft';
        }
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
        $audit->record('marketplace.listing_created', $request->user(), $tenant, $listing, ['product_id' => $product->uuid, 'submitted_for_approval' => $submittedForApproval]);

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
        $submittedForApproval = array_key_exists('status', $data) && $status === 'published' && app(MarketplaceSettingsService::class)->listingApprovalRequired() && $listing->status !== 'published';
        if ($submittedForApproval) {
            $status = 'draft';
        }
        $this->ensurePublishable($status, $listing->store, $product);
        if (isset($data['slug']) && MarketplaceListing::query()->where('slug', $data['slug'])->where('id', '!=', $listing->getKey())->exists()) {
            throw ValidationException::withMessages(['slug' => ['This marketplace product URL is already in use.']]);
        }
        $data['published_at'] = $status === 'published' ? ($listing->published_at ?? now()) : null;
        $listing->update($data);
        $audit->record('marketplace.listing_updated', $request->user(), $context->tenant(), $listing, ['submitted_for_approval' => $submittedForApproval]);

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

    private function getOrCreateStore(TenantContext $context): MarketplaceStore
    {
        $tenant = $context->tenant();
        $store = MarketplaceStore::query()->where('tenant_id', $tenant->id)->first();
        if (! $store) {
            $settings = app(MarketplaceSettingsService::class);
            if (! $settings->sellerRegistrationEnabled()) {
                throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('New marketplace seller registration is currently closed.');
            }
            $approved = ! $settings->sellerApprovalRequired() && $tenant->status === 'active';
            $store = MarketplaceStore::create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->display_name ?: $tenant->name,
                'slug' => $this->uniqueStoreSlug($tenant->slug ?: $tenant->name),
                'description' => $tenant->description,
                'approval_status' => $approved ? 'approved' : 'pending',
                'approved_at' => $approved ? now() : null,
                'is_active' => true,
            ]);
        }

        return $store->load('tenant');
    }

    private function ensurePublishable(string $status, MarketplaceStore $store, Product $product): void
    {
        if ($status !== 'published') {
            return;
        }
        if ($store->approval_status !== 'approved' || ! $store->is_active || $store->tenant?->status !== 'active') {
            throw ValidationException::withMessages(['status' => ['The marketplace store must be approved and active before publishing products.']]);
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
