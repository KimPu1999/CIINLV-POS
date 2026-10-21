<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'order_number' => $this->order_number,
            'promotion_code' => $this->promotion_code,
            'currency_code' => $this->currency_code,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'shipping_total' => $this->shipping_total,
            'tax_total' => $this->tax_total,
            'grand_total' => $this->grand_total,
            'delivery_address' => $this->delivery_address,
            'created_at' => $this->created_at?->toIso8601String(),
            'payments' => MarketplacePaymentResource::collection($this->whenLoaded('payments')),
            'vendor_orders' => MarketplaceVendorOrderResource::collection($this->whenLoaded('vendorOrders')),
            'events' => MarketplaceOrderEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
