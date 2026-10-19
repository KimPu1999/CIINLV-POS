<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['variant_id' => $this->variant?->uuid, 'sku' => $this->variant?->sku, 'product' => $this->variant?->product?->name, 'ordered_quantity' => $this->ordered_quantity, 'received_quantity' => $this->received_quantity, 'remaining_quantity' => bcsub((string) $this->ordered_quantity, (string) $this->received_quantity, 3), 'unit_cost' => $this->unit_cost, 'line_total' => number_format((float) $this->ordered_quantity * (float) $this->unit_cost, 2, '.', '')];
    }
}
