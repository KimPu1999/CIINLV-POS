<?php

namespace App\Http\Resources;

use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceCartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $listing = $this->listing;
        $variant = $this->variant;
        $variants = $listing?->product?->relationLoaded('variants') ? $listing->product->variants->where('status', 'active') : collect();

        return [
            'id' => $this->uuid,
            'listing_id' => $listing?->uuid,
            'listing_slug' => $listing?->slug,
            'variant_id' => $variant?->uuid,
            'quantity' => $this->quantity,
            'name' => $listing?->product?->name,
            'description' => $listing?->product?->description,
            'brand' => $listing?->product?->brand,
            'sku' => $variant?->sku,
            'attributes' => $variant?->attributes,
            'size' => is_array($variant?->attributes) ? ($variant->attributes['size'] ?? null) : null,
            'variants' => $variants->map(fn ($item): array => ['id' => $item->uuid, 'sku' => $item->sku, 'attributes' => $item->attributes, 'size' => is_array($item->attributes) ? ($item->attributes['size'] ?? null) : null, 'selling_price' => $item->selling_price, 'available_stock' => $item->balances->sum(fn ($balance): float => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity), 'status' => $item->status])->values()->all(),
            'unit_price' => $variant?->selling_price,
            'currency' => $this->cart?->currency_code ?: 'MMK',
            'image_url' => ($image = $listing?->product?->images?->first()) ? PublicMediaUrl::make($image->storage_path) : null,
            'store_id' => $listing?->store?->uuid,
            'store_name' => $listing?->store?->name,
            'store_slug' => $listing?->store?->slug,
            'available_stock' => $listing?->product?->track_inventory ? $variant?->balances?->sum(fn ($balance) => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity) : null,
            'track_inventory' => (bool) ($listing?->product?->track_inventory ?? true),
        ];
    }
}
