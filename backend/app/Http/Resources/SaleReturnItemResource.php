<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'sale_item_id' => $this->saleItem?->uuid, 'variant_id' => $this->variant?->uuid, 'quantity' => $this->quantity, 'unit_refund_amount' => $this->unit_refund_amount, 'refund_amount' => $this->refund_amount, 'restock' => $this->restock, 'disposition' => $this->disposition];
    }
}
