<?php

namespace App\Http\Resources;

use App\Services\TranslationService;
use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);
        $variants = $this->relationLoaded('variants') ? $this->variants->where('status', 'active') : collect();
        $listing = $this->relationLoaded('marketplaceListings') ? $this->marketplaceListings->first() : null;
        $defaultVariant = $variants->firstWhere('is_default', true) ?: $variants->first();
        $availableStock = $variants->sum(fn ($variant): float => $variant->relationLoaded('balances') ? $variant->balances->sum(fn ($balance): float => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity) : 0);
        $hasLowVariant = $variants->contains(fn ($variant): bool => $variant->relationLoaded('balances') && $variant->balances->contains(fn ($balance): bool => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity > 0 && (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity <= (float) $variant->reorder_level));
        $stockStatus = ! $this->track_inventory ? 'not_tracked' : ($availableStock <= 0 ? 'out_of_stock' : ($hasLowVariant ? 'low_stock' : 'in_stock'));
        $images = $this->relationLoaded('images') ? $this->images : collect();

        return [
            'id' => $this->uuid,
            'name' => $translations->localizedValue($this->resource, 'name', $locale),
            'brand' => $translations->localizedValue($this->resource, 'brand', $locale),
            'sku' => $defaultVariant?->sku,
            'selling_price' => $defaultVariant?->selling_price,
            'status' => $this->status,
            'track_inventory' => (bool) $this->track_inventory,
            'category' => $this->category ? ['id' => $this->category->uuid, 'name' => $translations->localizedValue($this->category, 'name', $locale)] : null,
            'image_url' => $images->first() ? PublicMediaUrl::make($images->first()->storage_path) : null,
            'available_stock' => $this->track_inventory ? $availableStock : null,
            'stock_status' => $stockStatus,
            'variants' => $variants->map(fn ($variant): array => [
                'id' => $variant->uuid,
                'sku' => $variant->sku,
                'size' => is_array($variant->attributes) ? ($variant->attributes['size'] ?? null) : null,
                'selling_price' => $variant->selling_price,
                'available_stock' => $variant->relationLoaded('balances') ? $variant->balances->sum(fn ($balance): float => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity) : null,
                'reorder_level' => $variant->reorder_level,
            ])->values()->all(),
            'listing' => $listing ? [
                'id' => $listing->uuid,
                'slug' => $listing->slug,
                'status' => $listing->status,
                'images' => $listing->relationLoaded('images') ? $listing->images->map(fn ($image): array => ['id' => (string) $image->id, 'url' => PublicMediaUrl::make($image->image_path), 'sort_order' => $image->sort_order])->values()->all() : [],
            ] : null,
            'locale' => $locale,
        ];
    }
}
