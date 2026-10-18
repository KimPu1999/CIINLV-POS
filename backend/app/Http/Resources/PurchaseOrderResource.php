<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'purchase_order_number' => $this->purchase_order_number, 'status' => $this->status, 'supplier' => new SupplierResource($this->whenLoaded('supplier')), 'destination' => new InventoryLocationResource($this->whenLoaded('destination')), 'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')), 'ordered_at' => $this->ordered_at];
    }
}
