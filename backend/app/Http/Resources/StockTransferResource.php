<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->relationLoaded('items') ? $this->items : collect();
        return ['id' => $this->uuid, 'transfer_number' => $this->transfer_number, 'status' => $this->status, 'source' => new InventoryLocationResource($this->whenLoaded('source')), 'destination' => new InventoryLocationResource($this->whenLoaded('destination')), 'items' => StockTransferItemResource::collection($this->whenLoaded('items')), 'item_count' => $this->items_count ?? $items->count(), 'total_requested_quantity' => number_format((float) $items->sum('requested_quantity'), 3, '.', '')];
    }
}
