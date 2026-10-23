<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MarketplaceVendorSaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->order;
        $payment = $order?->payments?->sortByDesc('id')->first();
        $shipment = $this->shipments?->sortByDesc('id')->first();
        $events = $order?->relationLoaded('events') ? $order->events->sortBy('created_at')->values() : collect();
        $confirmedAt = $events->first(fn ($event): bool => $event->event === 'order_created' || $event->to_status === 'confirmed')?->created_at;
        $completedAt = $events->first(fn ($event): bool => $event->to_status === 'delivered')?->created_at ?? $shipment?->delivered_at;
        $amountReceived = $payment?->amount_received ?: ($payment?->status === 'paid' ? $payment?->amount : '0.00');
        $paymentStatus = $payment?->status ?: $order?->payment_status;
        $shipmentStatus = $shipment?->status ?: match ($this->status) {
            'delivered' => 'delivered',
            'shipped' => 'shipped',
            default => 'ready',
        };
        $deliveryPaymentPending = $shipmentStatus === 'delivered' && $payment?->method === 'cod' && $paymentStatus === 'pending';
        $refundTotal = $order?->relationLoaded('refundRequests')
            ? $order->refundRequests->where('status', 'approved')->sum(fn ($refund): float => (float) ($refund->approved_amount ?? 0))
            : 0;

        return [
            'id' => $this->uuid,
            'sale_date' => $this->created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'confirmed_at' => $confirmedAt?->toIso8601String(),
            'completed_at' => $completedAt?->toIso8601String(),
            'vendor_order_number' => $this->vendor_order_number,
            'order_number' => $order?->order_number,
            'invoice_number' => $order?->invoice_number,
            'store' => [
                'id' => $this->store?->uuid,
                'name' => $this->store?->name,
                'logo_url' => $this->store?->logo_path ? Storage::disk('public')->url($this->store->logo_path) : null,
            ],
            'customer' => [
                'name' => $order?->customer?->name,
                'email' => $order?->customer?->email,
                'phone' => $order?->customer?->customerProfile?->phone,
            ],
            'customer_name' => $order?->customer?->name,
            'customer_email' => $order?->customer?->email,
            'customer_phone' => $order?->customer?->customerProfile?->phone,
            'billing_address' => $order?->delivery_address,
            'item_count' => $this->items?->sum(fn ($item): float => (float) $item->quantity) ?? 0,
            'items' => MarketplaceOrderItemResource::collection($this->whenLoaded('items')),
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'shipping_total' => $this->shipping_total,
            'grand_total' => $this->total,
            'total' => $this->total,
            'currency_code' => $this->currency_code,
            'order_status' => $order?->status,
            'vendor_status' => $this->status,
            'payment_method' => $payment?->method,
            'payment_status' => $paymentStatus,
            'payment_received_at' => ($payment?->paid_at ?? $payment?->received_at)?->toIso8601String(),
            'payment' => $payment ? new MarketplacePaymentResource($payment) : null,
            'payments' => MarketplacePaymentResource::collection($order?->payments ?? collect()),
            'amount_paid' => $paymentStatus === 'paid' ? ($amountReceived ?: $payment?->amount) : '0.00',
            'amount_received' => $amountReceived,
            'amount_due' => number_format(max((float) $this->total - (float) $amountReceived, 0), 2, '.', ''),
            'transaction_reference' => $payment?->transaction_reference,
            'provider_reference' => $payment?->provider_reference,
            'receipt_number' => $payment?->receipt_number,
            'received_by' => $payment?->receivedBy?->only(['id', 'name', 'email']),
            'shipment_status' => $shipmentStatus,
            'delivery_status_label' => $deliveryPaymentPending ? 'Delivered — Payment Pending' : ucfirst(str_replace('_', ' ', $shipmentStatus)),
            'delivery_type' => $shipment?->delivery_type,
            'carrier_name' => $shipment?->carrier_name,
            'tracking_number' => $shipment?->tracking_number,
            'tracking_id' => $shipment?->internal_tracking_id ?: $shipment?->tracking_number,
            'shipped_at' => $shipment?->shipped_at?->toIso8601String(),
            'delivered_at' => $shipment?->delivered_at?->toIso8601String(),
            'shipment' => $shipment ? new MarketplaceShipmentResource($shipment) : null,
            'shipments' => MarketplaceShipmentResource::collection($this->whenLoaded('shipments')),
            'invoice_available' => true,
            'receipt_available' => $paymentStatus === 'paid' && $payment?->status === 'paid',
            'refund_total' => number_format($refundTotal, 2, '.', ''),
            'refunds' => $order?->relationLoaded('refundRequests') ? $order->refundRequests->map(fn ($refund): array => [
                'id' => $refund->uuid,
                'status' => $refund->status,
                'requested_amount' => $refund->requested_amount,
                'approved_amount' => $refund->approved_amount,
                'currency_code' => $refund->currency_code,
                'reason' => $refund->reason,
                'reviewed_at' => $refund->reviewed_at?->toIso8601String(),
            ])->values() : [],
            'commission' => $this->relationLoaded('commission') && $this->commission ? [
                'amount' => $this->commission->commission_amount,
                'rate' => $this->commission->commission_rate,
                'seller_net_amount' => $this->commission->seller_net_amount,
                'status' => $this->commission->status,
            ] : null,
            'events' => $order?->relationLoaded('events') ? MarketplaceOrderEventResource::collection($events) : [],
            'shipment_events' => $shipment?->relationLoaded('events') ? MarketplaceShipmentEventResource::collection($shipment->events) : [],
        ];
    }
}
