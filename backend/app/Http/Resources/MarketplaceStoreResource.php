<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MarketplaceStoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'banner_url' => $this->banner_path ? Storage::disk('public')->url($this->banner_path) : null,
            'approval_status' => $this->approval_status,
            'is_active' => (bool) $this->is_active,
            'business_name' => $this->tenant?->name,
            'business_status' => $this->tenant?->status,
            'product_count' => $this->when(isset($this->listings_count), (int) $this->listings_count),
            'listings' => $this->whenLoaded('listings', fn () => MarketplaceListingResource::collection($this->listings)),
            'created_at' => $this->created_at,
        ];
    }
}
