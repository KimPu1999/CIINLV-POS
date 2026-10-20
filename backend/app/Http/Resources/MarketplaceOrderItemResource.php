<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->product_name_snapshot,
            'sku' => $this->sku_snapshot,
            'variant' => $this->variant_snapshot,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'line_total' => $this->line_total,
            'currency_code' => $this->currency_code,
        ];
    }
}
