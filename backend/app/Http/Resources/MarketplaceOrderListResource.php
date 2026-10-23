<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceOrderListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vendors = $this->relationLoaded('vendorOrders') ? $this->vendorOrders : collect();
        $shipments = $vendors->flatMap(fn ($vendor) => $vendor->relationLoaded('shipments') ? $vendor->shipments : collect());

        return [
            'id' => $this->uuid,
            'order_number' => $this->order_number,
            'currency_code' => $this->currency_code,
            'grand_total' => $this->grand_total,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'created_at' => $this->created_at?->toIso8601String(),
            'admin_hold' => (bool) $this->admin_hold,
            'admin_hold_reason' => $this->admin_hold_reason,
            'customer' => $this->whenLoaded('customer', fn () => [
                'name' => $this->customer?->name,
                'email' => $this->customer?->email,
                'phone' => $this->customer?->customerProfile?->phone,
            ]),
            'vendor_count' => $this->vendor_orders_count ?? $vendors->count(),
            'vendor_orders' => $vendors->map(fn ($vendor): array => [
                'vendor_order_number' => $vendor->vendor_order_number,
                'store_name' => $vendor->store?->name,
                'status' => $vendor->status,
                'fulfillment_status' => $vendor->fulfillment_status ?: 'pending',
                'shipment_statuses' => $vendor->relationLoaded('shipments')
                    ? $vendor->shipments->pluck('status')->filter()->values()->all()
                    : [],
                'tracking_numbers' => $vendor->relationLoaded('shipments')
                    ? $vendor->shipments->pluck('tracking_number')->filter()->values()->all()
                    : [],
            ])->values()->all(),
            'fulfillment_summary' => [
                'pending' => $vendors->whereIn('fulfillment_status', [null, 'pending'])->count(),
                'processing' => $vendors->where('fulfillment_status', 'active')->count(),
                'shipped' => $vendors->whereIn('fulfillment_status', ['shipped', 'in_transit'])->count(),
                'delivered' => $vendors->where('fulfillment_status', 'delivered')->count(),
                'cancelled' => $vendors->where('fulfillment_status', 'cancelled')->count(),
            ],
            'shipment_summary' => [
                'total' => $shipments->count(),
                'ready' => $shipments->where('status', 'ready')->count(),
                'in_transit' => $shipments->where('status', 'in_transit')->count(),
                'delivered' => $shipments->whereIn('status', ['delivered', 'completed'])->count(),
                'failed' => $shipments->whereIn('status', ['delivery_failed', 'returned', 'cancelled'])->count(),
            ],
        ];
    }
}
