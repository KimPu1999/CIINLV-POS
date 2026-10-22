<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'invoice_number' => $this->invoice_number, 'sale_id' => $this->sale?->uuid, 'sale_number' => $this->sale?->sale_number, 'store_id' => $this->store?->uuid, 'store_name' => $this->store?->name, 'status' => $this->status, 'currency_code' => $this->currency_code, 'subtotal' => $this->subtotal, 'tax_total' => $this->tax_total, 'grand_total' => $this->grand_total, 'paid_total' => $this->paid_total, 'balance_due' => $this->balance_due, 'issued_at' => $this->issued_at?->toIso8601String(), 'payments' => PaymentResource::collection($this->whenLoaded('payments'))];
    }
}
