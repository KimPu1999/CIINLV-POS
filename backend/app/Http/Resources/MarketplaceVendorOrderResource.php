<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceVendorOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->order?->payments?->sortByDesc('id')->first();
        $amountReceived = $payment?->amount_received ?: ($payment?->status === 'paid' ? $payment?->amount : '0.00');

        return [
            'id' => $this->uuid,
            'vendor_order_number' => $this->vendor_order_number,
            'order_number' => $this->order?->order_number,
            'invoice_number' => $this->order?->invoice_number,
            'customer_name' => $this->order?->customer?->name,
            'customer_email' => $this->order?->customer?->email,
            'store_id' => $this->store?->uuid,
            'store_name' => $this->store?->name,
            'tenant_id' => $this->tenant?->uuid,
            'status' => $this->status,
            'order_status' => $this->order?->status,
            'payment_status' => $this->order?->payment_status,
            'payment_method' => $this->order?->payments?->sortByDesc('id')->first()?->method,
            'amount_received' => $amountReceived,
            'amount_due' => number_format(max((float) $this->total - (float) $amountReceived, 0), 2, '.', ''),
            'receipt_available' => $this->order?->payment_status === 'paid' && $payment?->status === 'paid',
            'fulfillment_status' => $this->fulfillment_status ?: 'pending',
            'fulfillment_activated_at' => $this->fulfillment_activated_at?->toIso8601String(),
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
            'shipments' => MarketplaceShipmentResource::collection($this->whenLoaded('shipments')),
        ];
    }
}
