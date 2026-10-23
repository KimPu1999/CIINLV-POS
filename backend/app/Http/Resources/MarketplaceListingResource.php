<?php

namespace App\Http\Resources;

use App\Services\TranslationService;
use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);
        $product = $this->product;
        $variants = $product?->relationLoaded('variants') ? $product->variants->where('status', 'active') : collect();
        $images = $product?->relationLoaded('images') ? $product->images : collect();
        $listingImages = $this->relationLoaded('images') ? $this->images : collect();
        $firstVariant = $variants->first();
        $defaultVariant = $variants->firstWhere('is_default', true) ?: $firstVariant;
        $availableStock = $variants->sum(fn ($variant): float => $variant->relationLoaded('balances')
            ? $variant->balances->sum(fn ($balance): float => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity)
            : 0);
        $locations = $variants->flatMap(fn ($variant) => $variant->relationLoaded('balances') ? $variant->balances : collect())
            ->filter(fn ($balance) => $balance->relationLoaded('location') && $balance->location)
            ->map(fn ($balance): array => ['id' => $balance->location->uuid, 'name' => $balance->location->name, 'code' => $balance->location->code])
            ->unique('id')->values()->all();
        $imageUrls = $images->map(fn ($image): array => [
            'id' => $image->uuid,
            'url' => PublicMediaUrl::make($image->storage_path),
            'sort_order' => $image->sort_order,
            'is_primary' => (bool) $image->is_primary,
        ])->values()->all();
        $listingImageUrls = $listingImages->map(fn ($image): array => [
            'id' => (string) $image->id,
            'url' => PublicMediaUrl::make($image->image_path),
            'sort_order' => $image->sort_order,
        ])->values()->all();
        $allImages = [...$listingImageUrls, ...$imageUrls];
        $category = $product?->category;
        $reviews = $this->relationLoaded('approvedReviews') ? $this->approvedReviews : collect();

        return [
            'id' => $this->uuid,
            'product_id' => $product?->uuid,
            'name' => $product ? $translations->localizedValue($product, 'name', $locale) : null,
            'slug' => $this->slug,
            'description' => $product ? $translations->localizedValue($product, 'description', $locale) : null,
            'brand' => $product ? $translations->localizedValue($product, 'brand', $locale) : null,
            'sku' => $defaultVariant?->sku,
            'barcode' => $defaultVariant?->barcode,
            'price' => $defaultVariant?->selling_price,
            'currency' => $this->tenant?->currency_code ?: 'MMK',
            'status' => $this->status,
            'product_status' => $product?->status,
            'store_approval_status' => $this->store?->approval_status,
            'store_active' => (bool) ($this->store?->is_active ?? false),
            'business_id' => $this->tenant?->uuid,
            'business_name' => $this->tenant?->name,
            'business_status' => $this->tenant?->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'track_inventory' => (bool) ($product?->track_inventory ?? true),
            'has_sizes' => (bool) ($product?->has_sizes ?? false),
            'available_stock' => $product?->track_inventory ? $availableStock : null,
            'branches' => $locations,
            'store_id' => $this->store?->uuid,
            'store_name' => $this->store?->name,
            'store_slug' => $this->store?->slug,
            'category_id' => $category?->uuid,
            'category_slug' => $category?->slug,
            'category_name' => $category ? $translations->localizedValue($category, 'name', $locale) : null,
            'image_url' => $allImages[0]['url'] ?? null,
            'images' => $allImages,
            'variants' => $variants->map(fn ($variant): array => [
                'id' => $variant->uuid,
                'sku' => $variant->sku,
                'unit' => $variant->unit,
                'attributes' => $variant->attributes,
                'size' => is_array($variant->attributes) ? ($variant->attributes['size'] ?? null) : null,
                'is_default' => (bool) $variant->is_default,
                'selling_price' => $variant->selling_price,
                'available_stock' => $variant->relationLoaded('balances')
                    ? $variant->balances->sum(fn ($balance): float => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity)
                    : null,
                'stock_by_location' => $variant->relationLoaded('balances') ? $variant->balances->map(fn ($balance): array => [
                    'location_id' => $balance->inventory_location_id,
                    'location_name' => $balance->relationLoaded('location') ? $balance->location?->name : null,
                    'on_hand' => $balance->on_hand_quantity,
                    'reserved' => $balance->reserved_quantity,
                    'available' => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity,
                ])->values()->all() : [],
                'status' => $variant->status,
            ])->values()->all(),
            'locale' => $locale,
            'reviews' => MarketplaceReviewResource::collection($reviews),
            'rating_summary' => [
                'average_rating' => number_format((float) ($reviews->avg('rating') ?? 0), 2, '.', ''),
                'total_reviews' => $reviews->count(),
                'distribution' => collect(range(1, 5))->mapWithKeys(fn (int $rating): array => [$rating => $reviews->where('rating', $rating)->count()]),
            ],
        ];
    }
}
