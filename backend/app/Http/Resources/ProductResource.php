<?php

namespace App\Http\Resources;

use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variants = $this->relationLoaded('variants') ? $this->variants : collect();
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);

        return ['id' => $this->uuid, 'category' => new CategoryResource($this->whenLoaded('category')), 'name' => $translations->localizedValue($this->resource, 'name', $locale), 'slug' => $this->slug, 'description' => $translations->localizedValue($this->resource, 'description', $locale), 'brand' => $translations->localizedValue($this->resource, 'brand', $locale), 'status' => $this->status, 'track_inventory' => $this->track_inventory, 'tax_exempt' => (bool) $this->tax_exempt, 'sku' => $variants->first()?->sku, 'selling_price' => $variants->first()?->selling_price, 'available_stock' => $variants->sum(fn ($variant) => $variant->relationLoaded('balances') ? $variant->balances->sum(fn ($balance) => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity) : 0), 'variants' => ProductVariantResource::collection($this->whenLoaded('variants')), 'images' => ProductImageResource::collection($this->whenLoaded('images')), 'locale' => $locale, 'translation_status' => $translations->translationStatus($this->resource, $locale), 'created_at' => $this->created_at];
    }
}
