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
            'admin_hold' => (bool) $this->admin_hold,
            'admin_hold_reason' => $this->admin_hold_reason,
            'admin_hold_at' => $this->admin_hold_at?->toIso8601String(),
            'admin_hold_by' => $this->whenLoaded('adminHoldUser', fn () => $this->adminHoldUser?->only(['id', 'name', 'email'])),
            'payments' => MarketplacePaymentResource::collection($this->whenLoaded('payments')),
            'vendor_orders' => MarketplaceVendorOrderResource::collection($this->whenLoaded('vendorOrders')),
            'events' => MarketplaceOrderEventResource::collection($this->whenLoaded('events')),
            'cancellation_requests' => $this->whenLoaded('cancellationRequests', fn () => $this->cancellationRequests->map(fn ($request): array => ['id' => $request->uuid, 'status' => $request->status, 'reason' => $request->reason, 'created_at' => $request->created_at?->toIso8601String()])),
            'return_requests' => $this->whenLoaded('returnRequests', fn () => $this->returnRequests->map(fn ($request): array => ['id' => $request->uuid, 'status' => $request->status, 'reason' => $request->reason, 'created_at' => $request->created_at?->toIso8601String()])),
            'refund_requests' => $this->whenLoaded('refundRequests', fn () => $this->refundRequests->map(fn ($request): array => ['id' => $request->uuid, 'status' => $request->status, 'reason' => $request->reason, 'requested_amount' => $request->requested_amount, 'approved_amount' => $request->approved_amount, 'created_at' => $request->created_at?->toIso8601String()])),
        ];
    }
}
