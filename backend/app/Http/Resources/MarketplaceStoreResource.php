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
            'public_contact_email' => $this->public_contact_email,
            'public_contact_phone' => $this->public_contact_phone,
            'business_category' => $this->business_category,
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'banner_url' => $this->banner_path ? Storage::disk('public')->url($this->banner_path) : null,
            'approval_status' => $this->approval_status,
            'is_active' => (bool) $this->is_active,
            'notification_preferences' => $this->notification_preferences ?: [],
            'fulfillment_preferences' => $this->fulfillment_preferences ?: [],
            'business_name' => $this->tenant?->name,
            'business_status' => $this->tenant?->status,
            'business_verification_status' => $this->tenant?->verification_status,
            'business_id' => $this->tenant?->business_code,
            'business_email' => $this->tenant?->email,
            'business_phone' => $this->tenant?->phone,
            'product_count' => $this->when(isset($this->listings_count), (int) $this->listings_count),
            'order_count' => $this->when(isset($this->vendor_orders_count), (int) $this->vendor_orders_count),
            'listings' => $this->whenLoaded('listings', fn () => MarketplaceListingResource::collection($this->listings)),
            'created_at' => $this->created_at,
        ];
    }
}
