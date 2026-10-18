<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'category' => new CategoryResource($this->whenLoaded('category')), 'name' => $this->name, 'slug' => $this->slug, 'description' => $this->description, 'brand' => $this->brand, 'status' => $this->status, 'track_inventory' => $this->track_inventory, 'variants' => ProductVariantResource::collection($this->whenLoaded('variants')), 'images' => ProductImageResource::collection($this->whenLoaded('images')), 'created_at' => $this->created_at];
    }
}
