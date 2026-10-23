<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'sale_number' => $this->sale_number, 'store_id' => $this->store?->uuid, 'store_name' => $this->store?->name, 'register_id' => $this->register?->uuid, 'cashier_shift_id' => $this->cashier_shift_id, 'cashier_user_id' => $this->cashier_user_id, 'cashier_name' => $this->cashier?->name, 'salesperson_user_id' => $this->salesperson_user_id, 'salesperson_name' => $this->salesperson?->name, 'receipt_number' => $this->receipt?->receipt_number, 'subtotal' => $this->subtotal, 'discount_total' => $this->discount_total, 'taxable_amount' => $this->taxable_amount, 'tax_total' => $this->tax_total, 'service_charge_total' => $this->service_charge_total, 'fee_total' => $this->fee_total, 'rounding_adjustment' => $this->rounding_adjustment, 'grand_total' => $this->grand_total, 'paid_total' => $this->paid_total, 'balance_due' => $this->balance_due, 'change_due' => $this->change_due, 'refunded_total' => $this->refunded_total, 'tax_breakdown' => $this->tax_breakdown, 'currency_code' => $this->currency_code, 'status' => $this->status, 'payment_status' => $this->payment_status, 'completed_at' => $this->completed_at, 'item_count' => $this->items_count ?? ($this->relationLoaded('items') ? $this->items->count() : null), 'items' => SaleItemResource::collection($this->whenLoaded('items')), 'payments' => PaymentResource::collection($this->whenLoaded('payments')), 'returns' => $this->whenLoaded('returns')];
    }
}
