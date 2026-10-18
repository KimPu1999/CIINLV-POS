<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'variant_id' => $this->variant?->uuid, 'product_name' => $this->product_name_snapshot, 'sku' => $this->sku_snapshot, 'barcode' => $this->barcode_snapshot, 'unit' => $this->unit_snapshot, 'quantity' => $this->quantity, 'returned_quantity' => $this->returned_quantity, 'unit_price' => $this->unit_price, 'discount_amount' => $this->discount_amount, 'tax_amount' => $this->tax_amount, 'line_total' => $this->line_total, 'unit_cost_at_sale' => $this->unit_cost_at_sale];
    }
}
