<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['variant_id' => $this->variant?->uuid, 'sku' => $this->variant?->sku, 'ordered_quantity' => $this->ordered_quantity, 'received_quantity' => $this->received_quantity, 'unit_cost' => $this->unit_cost];
    }
}
