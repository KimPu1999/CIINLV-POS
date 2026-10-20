<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'vendor_order_id' => $this->vendorOrder?->uuid,
            'vendor_order_number' => $this->vendorOrder?->vendor_order_number,
            'store_name' => $this->vendorOrder?->store?->name,
            'delivery_type' => $this->delivery_type,
            'carrier_name' => $this->carrier_name,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => $this->tracking_url,
            'internal_tracking_id' => $this->internal_tracking_id,
            'status' => $this->status,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'delivery_notes' => $this->delivery_notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'events' => MarketplaceShipmentEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
