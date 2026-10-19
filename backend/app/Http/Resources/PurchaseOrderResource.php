<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->relationLoaded('items') ? $this->items : collect();
        return ['id' => $this->uuid, 'purchase_order_number' => $this->purchase_order_number, 'status' => $this->status, 'supplier' => new SupplierResource($this->whenLoaded('supplier')), 'destination' => new InventoryLocationResource($this->whenLoaded('destination')), 'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')), 'item_count' => $this->items_count ?? $items->count(), 'ordered_value' => number_format((float) $items->sum(fn ($item) => (float) $item->ordered_quantity * (float) $item->unit_cost), 2, '.', ''), 'ordered_at' => $this->ordered_at];
    }
}
