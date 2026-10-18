<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['variant_id' => $this->variant?->uuid, 'sku' => $this->variant?->sku, 'requested_quantity' => $this->requested_quantity, 'reserved_quantity' => $this->reserved_quantity, 'dispatched_quantity' => $this->dispatched_quantity, 'received_quantity' => $this->received_quantity, 'damaged_quantity' => $this->damaged_quantity, 'missing_quantity' => $this->missing_quantity];
    }
}
