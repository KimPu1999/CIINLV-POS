<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MarketplaceCartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $listing = $this->listing;
        $variant = $this->variant;

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
            'unit_price' => $variant?->selling_price,
            'currency' => $this->cart?->currency_code ?: 'MMK',
            'image_url' => ($image = $listing?->product?->images?->first()) ? Storage::disk('public')->url($image->storage_path) : null,
            'store_id' => $listing?->store?->uuid,
            'store_name' => $listing?->store?->name,
            'store_slug' => $listing?->store?->slug,
            'available_stock' => $listing?->product?->track_inventory ? $variant?->balances?->sum(fn ($balance) => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity) : null,
            'track_inventory' => (bool) ($listing?->product?->track_inventory ?? true),
        ];
    }
}
