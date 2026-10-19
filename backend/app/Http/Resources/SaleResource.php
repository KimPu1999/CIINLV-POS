<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'sale_number' => $this->sale_number, 'store_id' => $this->store?->uuid, 'store_name' => $this->store?->name, 'register_id' => $this->register?->uuid, 'cashier_user_id' => $this->cashier_user_id, 'cashier_name' => $this->cashier?->name, 'receipt_number' => $this->receipt?->receipt_number, 'subtotal' => $this->subtotal, 'discount_total' => $this->discount_total, 'tax_total' => $this->tax_total, 'grand_total' => $this->grand_total, 'paid_total' => $this->paid_total, 'balance_due' => $this->balance_due, 'change_due' => $this->change_due, 'refunded_total' => $this->refunded_total, 'currency_code' => $this->currency_code, 'status' => $this->status, 'payment_status' => $this->payment_status, 'completed_at' => $this->completed_at, 'items' => SaleItemResource::collection($this->whenLoaded('items')), 'payments' => PaymentResource::collection($this->whenLoaded('payments')), 'returns' => $this->whenLoaded('returns')];
    }
}
