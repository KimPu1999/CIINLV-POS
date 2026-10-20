<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\MarketplaceCategoryResource;
use App\Http\Resources\MarketplaceListingResource;
use App\Http\Resources\MarketplaceStoreResource;
use App\Models\Category;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplacePublicController
{
    public function stores(Request $request): JsonResponse
    {
        $stores = MarketplaceStore::query()
            ->public()
            ->with('tenant')
            ->withCount(['listings' => fn (Builder $query) => $query->public()])
            ->when($request->filled('search'), fn (Builder $query) => $query->where(function (Builder $search) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $search->where('marketplace_stores.name', 'ilike', $term)
                    ->orWhere('marketplace_stores.description', 'ilike', $term);
            }))
            ->orderBy('marketplace_stores.name')
            ->paginate($this->perPage($request, 24));

        return $this->paginated(MarketplaceStoreResource::collection($stores), $stores);
    }

    public function store(MarketplaceStore $store): JsonResponse
    {
        $store = MarketplaceStore::query()
            ->public()
            ->with(['tenant', 'listings' => fn (Builder $query) => $query->public()->with($this->listingRelations())])
            ->where('slug', $store->slug)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => new MarketplaceStoreResource($store)]);
    }

    public function products(Request $request): JsonResponse
    {
        $query = $this->publicListings($request)
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $query->where(function (Builder $search) use ($term): void {
                    $search->whereHas('product', fn (Builder $product) => $product
                        ->where('products.name', 'ilike', $term)
                        ->orWhere('products.brand', 'ilike', $term))
                        ->orWhereHas('store', fn (Builder $store) => $store->where('marketplace_stores.name', 'ilike', $term));
                });
            })
            ->when($request->filled('category'), fn (Builder $query) => $query->whereHas('product.category', fn (Builder $category) => $category
                ->where('categories.slug', $request->string('category'))
                ->orWhere('categories.uuid', $request->string('category'))))
            ->when($request->filled('store'), fn (Builder $query) => $query->whereHas('store', fn (Builder $store) => $store
                ->where('marketplace_stores.slug', $request->string('store'))
                ->orWhere('marketplace_stores.uuid', $request->string('store'))));

        $sort = (string) $request->input('sort', 'newest');
        if ($sort === 'oldest') {
            $query->orderBy('marketplace_listings.created_at');
        } else {
            $query->orderByDesc('marketplace_listings.published_at');
        }

        $listings = $query
            ->with($this->listingRelations())
            ->paginate($this->perPage($request, 24));

        return $this->paginated(MarketplaceListingResource::collection($listings), $listings);
    }

    public function product(MarketplaceListing $product): JsonResponse
    {
        $listing = $this->publicListings()
            ->with($this->listingRelations())
            ->where('marketplace_listings.slug', $product->slug)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => new MarketplaceListingResource($listing)]);
    }

    public function categories(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->where('categories.status', 'active')
            ->whereHas('products', fn (Builder $product) => $product->whereHas('marketplaceListings', fn (Builder $listing) => $listing->public()))
            ->withCount(['products as public_products_count' => fn (Builder $product) => $product->whereHas('marketplaceListings', fn (Builder $listing) => $listing->public())])
            ->orderBy('categories.name')
            ->paginate($this->perPage($request, 50));

        return $this->paginated(MarketplaceCategoryResource::collection($categories), $categories);
    }

    public function search(Request $request): JsonResponse
    {
        return $this->products($request);
    }

    private function publicListings(?Request $request = null): Builder
    {
        return MarketplaceListing::query()->public();
    }

    private function listingRelations(): array
    {
        return [
            'store.tenant',
            'tenant',
            'product.category',
            'product.images',
            'product.variants' => fn ($query) => $query->where('product_variants.status', 'active')->with('balances'),
            'images',
        ];
    }

    private function perPage(Request $request, int $default): int
    {
        return min(max((int) $request->input('per_page', $default), 1), 100);
    }

    private function paginated(mixed $resource, mixed $paginator): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $resource,
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
