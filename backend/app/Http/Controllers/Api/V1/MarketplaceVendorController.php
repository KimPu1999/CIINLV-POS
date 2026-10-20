<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\MarketplaceListingRequest;
use App\Http\Requests\Marketplace\UpdateMarketplaceStoreRequest;
use App\Http\Resources\MarketplaceListingResource;
use App\Http\Resources\MarketplaceStoreResource;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceStore;
use App\Models\Product;
use App\Services\MarketplaceAuditLogger;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketplaceVendorController
{
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

    private function getOrCreateStore(TenantContext $context): MarketplaceStore
    {
        $tenant = $context->tenant();
        $store = MarketplaceStore::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['name' => $tenant->display_name ?: $tenant->name, 'slug' => $this->uniqueStoreSlug($tenant->slug ?: $tenant->name), 'description' => $tenant->description, 'approval_status' => 'pending', 'is_active' => true],
        );

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
