<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\MarketplaceCategoryResource;
use App\Http\Resources\MarketplaceListingResource;
use App\Http\Resources\MarketplaceStoreResource;
use App\Models\Category;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrderItem;
use App\Models\MarketplaceStore;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\MarketplaceSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketplacePublicController
{
    public function __construct(private readonly MarketplaceSettingsService $settings) {}

    public function home(Request $request): JsonResponse
    {
        $this->settings->assertAvailable();

        $categories = Category::query()
            ->where('categories.status', 'active')
            ->whereHas('products', fn (Builder $product) => $product->whereHas('marketplaceListings', fn (Builder $listing) => $listing->public()))
            ->withCount(['products as public_products_count' => fn (Builder $product) => $product->whereHas('marketplaceListings', fn (Builder $listing) => $listing->public())])
            ->orderByDesc('public_products_count')
            ->orderBy('categories.name')
            ->limit(8)
            ->get();

        $stores = MarketplaceStore::query()
            ->public()
            ->with('tenant')
            ->withCount(['listings' => fn (Builder $query) => $query->public()])
            ->orderByDesc('listings_count')
            ->orderBy('marketplace_stores.name')
            ->limit(6)
            ->get();

        $newArrivals = $this->publicListings()
            ->with($this->homeListingRelations())
            ->orderByDesc('marketplace_listings.published_at')
            ->orderBy('marketplace_listings.id')
            ->limit(8)
            ->get();

        // There is no featured-listing configuration in the current schema. Keep
        // this capability explicit instead of presenting an arbitrary page slice
        // as curated content.
        $featured = collect();

        // Only fully paid, non-cancelled and non-refunded orders are included.
        // Excluding partially-refunded orders is intentional until item-level
        // refund quantities are stored, so the homepage never overstates units.
        $bestSellerRows = MarketplaceOrderItem::query()
            ->select('marketplace_listing_id')
            ->selectRaw('SUM(quantity) AS units_sold')
            ->whereNotNull('marketplace_listing_id')
            ->whereHas('order', fn (Builder $order) => $order
                ->where('payment_status', 'paid')
                ->whereIn('status', ['confirmed', 'processing', 'partially_shipped', 'shipped', 'delivered']))
            ->groupBy('marketplace_listing_id')
            ->orderByDesc('units_sold')
            ->orderBy('marketplace_listing_id')
            ->limit(8)
            ->get();
        $bestSellerIds = $bestSellerRows->pluck('marketplace_listing_id')->map(fn ($id): int => (int) $id)->all();
        $bestSellers = $bestSellerIds === [] ? collect() : $this->publicListings()
            ->whereIn('marketplace_listings.id', $bestSellerIds)
            ->with($this->homeListingRelations())
            ->get()
            ->sortBy(fn (MarketplaceListing $listing): int => array_search((int) $listing->getKey(), $bestSellerIds, true))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => MarketplaceCategoryResource::collection($categories)->resolve($request),
                'stores' => MarketplaceStoreResource::collection($stores)->resolve($request),
                'featured_products' => MarketplaceListingResource::collection($featured)->resolve($request),
                'featured_available' => false,
                'new_arrivals' => MarketplaceListingResource::collection($newArrivals)->resolve($request),
                'best_sellers' => MarketplaceListingResource::collection($bestSellers)->resolve($request),
                'best_sellers_available' => $bestSellers->isNotEmpty(),
                'best_sellers_basis' => 'Fully paid, non-cancelled and non-refunded orders; all-time.',
            ],
        ]);
    }

    public function stores(Request $request): JsonResponse
    {
        $this->settings->assertAvailable();
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

    public function related(Request $request, MarketplaceListing $product): JsonResponse
    {
        $this->settings->assertAvailable();
        $current = $this->publicListings()->with('product')->whereKey($product->getKey())->firstOrFail();
        $categoryId = $current->product?->category_id;
        $limit = min(max((int) $request->input('per_page', 8), 4), 8);
        $related = $this->publicListings()
            ->where('marketplace_listings.id', '<>', $current->getKey())
            ->when($categoryId, fn (Builder $query) => $query->whereHas('product', fn (Builder $productQuery) => $productQuery->where('products.category_id', $categoryId)))
            ->with($this->homeListingRelations())
            ->orderByDesc('marketplace_listings.published_at')
            ->orderBy('marketplace_listings.id')
            ->limit($limit)
            ->get();

        return response()->json(['success' => true, 'data' => MarketplaceListingResource::collection($related), 'meta' => ['total' => $related->count(), 'per_page' => $limit, 'current_page' => 1, 'last_page' => 1]]);
    }

    public function store(MarketplaceStore $store): JsonResponse
    {
        $this->settings->assertAvailable();
        $store = MarketplaceStore::query()
            ->public()
            ->with(['tenant', 'listings' => fn ($query) => $query->public()->with($this->listingRelations())])
            ->where('slug', $store->slug)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => new MarketplaceStoreResource($store)]);
    }

    public function products(Request $request): JsonResponse
    {
        $this->settings->assertAvailable();
        $query = $this->publicListings($request)
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $query->where(function (Builder $search) use ($term): void {
                    $search->whereHas('product', fn (Builder $product) => $product
                        ->where('products.name', 'ilike', $term)
                        ->orWhere('products.brand', 'ilike', $term)
                        ->orWhereHas('variants', fn (Builder $variant) => $variant->where('product_variants.sku', 'ilike', $term)))
                        ->orWhereHas('store', fn (Builder $store) => $store->where('marketplace_stores.name', 'ilike', $term));
                });
            })
            ->when($request->filled('category'), function (Builder $query) use ($request): void {
                $category = (string) $request->input('category');
                $query->whereHas('product.category', function (Builder $categoryQuery) use ($category): void {
                    $categoryQuery->where('categories.slug', $category);
                    if (Str::isUuid($category)) {
                        $categoryQuery->orWhere('categories.uuid', $category);
                    }
                });
            })
            ->when($request->filled('store'), function (Builder $query) use ($request): void {
                $store = (string) $request->input('store');
                $query->whereHas('store', function (Builder $storeQuery) use ($store): void {
                    $storeQuery->where('marketplace_stores.slug', $store);
                    if (Str::isUuid($store)) {
                        $storeQuery->orWhere('marketplace_stores.uuid', $store);
                    }
                });
            });

        $query
            ->when($request->filled('min_price'), fn (Builder $listing) => $listing->whereHas('product.variants', fn (Builder $variant) => $variant
                ->where('product_variants.status', 'active')
                ->where('product_variants.selling_price', '>=', (float) $request->input('min_price'))))
            ->when($request->filled('max_price'), fn (Builder $listing) => $listing->whereHas('product.variants', fn (Builder $variant) => $variant
                ->where('product_variants.status', 'active')
                ->where('product_variants.selling_price', '<=', (float) $request->input('max_price'))));

        $sort = (string) $request->input('sort', 'newest');
        $query->when($sort === 'oldest', fn (Builder $listing) => $listing->orderBy('marketplace_listings.created_at'))
            ->when($sort === 'name', fn (Builder $listing) => $listing->orderBy(Product::query()->select('name')->whereColumn('products.id', 'marketplace_listings.product_id')->limit(1)))
            ->when($sort === 'price-low' || $sort === 'price-high', fn (Builder $listing) => $listing->orderBy(ProductVariant::query()->select('selling_price')->whereColumn('product_variants.product_id', 'marketplace_listings.product_id')->where('product_variants.status', 'active')->orderBy('product_variants.id')->limit(1), $sort === 'price-high' ? 'desc' : 'asc'))
            ->when(! in_array($sort, ['oldest', 'name', 'price-low', 'price-high'], true), fn (Builder $listing) => $listing->orderByDesc('marketplace_listings.published_at'))
            ->orderBy('marketplace_listings.id');

        $listings = $query
            ->with($this->listingRelations())
            ->paginate($this->perPage($request, 24));

        return $this->paginated(MarketplaceListingResource::collection($listings), $listings);
    }

    public function product(MarketplaceListing $product): JsonResponse
    {
        $this->settings->assertAvailable();
        $listing = $this->publicListings()
            ->with($this->listingRelations())
            ->where('marketplace_listings.slug', $product->slug)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => new MarketplaceListingResource($listing)]);
    }

    public function categories(Request $request): JsonResponse
    {
        $this->settings->assertAvailable();
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
        return MarketplaceListing::query()
            ->public()
            ->withCount(['approvedReviews as approved_reviews_count'])
            ->withAvg('approvedReviews as approved_reviews_average', 'rating');
    }

    private function listingRelations(): array
    {
        return [
            'store.tenant',
            'tenant',
            'product.category',
            'product.images',
            'product.variants' => fn ($query) => $query->where('product_variants.status', 'active')->orderByDesc('is_default')->orderBy('id')->with('balances'),
            'images',
        ];
    }

    private function homeListingRelations(): array
    {
        return [
            'store.tenant',
            'tenant',
            'product.category',
            'product.images',
            'product.variants' => fn ($query) => $query->where('product_variants.status', 'active')->orderByDesc('is_default')->orderBy('id')->with('balances'),
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
