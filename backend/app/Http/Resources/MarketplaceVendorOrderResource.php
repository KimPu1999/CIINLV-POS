<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceVendorOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'vendor_order_number' => $this->vendor_order_number,
            'order_number' => $this->order?->order_number,
            'store_id' => $this->store?->uuid,
            'store_name' => $this->store?->name,
            'tenant_id' => $this->tenant?->uuid,
            'status' => $this->status,
            'payment_status' => $this->order?->payment_status,
            'created_at' => $this->created_at?->toIso8601String(),
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'shipping_total' => $this->shipping_total,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'currency_code' => $this->currency_code,
            'delivery_options' => $this->delivery_options,
            'delivery_address' => $this->order?->delivery_address,
            'items' => MarketplaceOrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
