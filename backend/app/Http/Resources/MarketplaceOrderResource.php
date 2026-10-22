<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->relationLoaded('payments') ? $this->payments->sortByDesc('id')->first() : null;
        $amountReceived = $payment?->amount_received ?: ($payment?->status === 'paid' ? $payment?->amount : '0.00');

        return [
            'id' => $this->uuid,
            'order_number' => $this->order_number,
            'invoice_number' => $this->invoice_number,
            'promotion_code' => $this->promotion_code,
            'currency_code' => $this->currency_code,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'shipping_total' => $this->shipping_total,
            'tax_total' => $this->tax_total,
            'grand_total' => $this->grand_total,
            'amount_received' => $amountReceived,
            'amount_due' => number_format(max((float) $this->grand_total - (float) $amountReceived, 0), 2, '.', ''),
            'receipt_available' => $this->payment_status === 'paid' && $payment?->status === 'paid',
            'delivery_address' => $this->delivery_address,
            'created_at' => $this->created_at?->toIso8601String(),
            'payments' => MarketplacePaymentResource::collection($this->whenLoaded('payments')),
            'vendor_orders' => MarketplaceVendorOrderResource::collection($this->whenLoaded('vendorOrders')),
            'events' => MarketplaceOrderEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
