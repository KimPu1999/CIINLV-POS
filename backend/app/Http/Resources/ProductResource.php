<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variants = $this->relationLoaded('variants') ? $this->variants : collect();
        return ['id' => $this->uuid, 'category' => new CategoryResource($this->whenLoaded('category')), 'name' => $this->name, 'slug' => $this->slug, 'description' => $this->description, 'brand' => $this->brand, 'status' => $this->status, 'track_inventory' => $this->track_inventory, 'sku' => $variants->first()?->sku, 'selling_price' => $variants->first()?->selling_price, 'available_stock' => $variants->sum(fn ($variant) => $variant->relationLoaded('balances') ? $variant->balances->sum(fn ($balance) => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity) : 0), 'variants' => ProductVariantResource::collection($this->whenLoaded('variants')), 'images' => ProductImageResource::collection($this->whenLoaded('images')), 'created_at' => $this->created_at];
    }
}
