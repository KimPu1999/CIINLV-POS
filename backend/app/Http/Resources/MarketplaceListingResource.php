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
        $availableStock = $variants->sum(fn ($variant): float => $variant->relationLoaded('balances')
            ? $variant->balances->sum(fn ($balance): float => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity)
            : 0);
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

        return [
            'id' => $this->uuid,
            'product_id' => $product?->uuid,
            'name' => $product ? $translations->localizedValue($product, 'name', $locale) : null,
            'slug' => $this->slug,
            'description' => $product ? $translations->localizedValue($product, 'description', $locale) : null,
            'brand' => $product ? $translations->localizedValue($product, 'brand', $locale) : null,
            'price' => $firstVariant?->selling_price,
            'currency' => $this->tenant?->currency_code ?: 'MMK',
            'status' => $this->status,
            'track_inventory' => (bool) ($product?->track_inventory ?? true),
            'available_stock' => $product?->track_inventory ? $availableStock : null,
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
                'selling_price' => $variant->selling_price,
                'available_stock' => $variant->relationLoaded('balances')
                    ? $variant->balances->sum(fn ($balance): float => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity)
                    : null,
                'status' => $variant->status,
            ])->values()->all(),
            'locale' => $locale,
        ];
    }
}
