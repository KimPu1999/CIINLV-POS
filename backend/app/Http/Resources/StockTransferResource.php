<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'transfer_number' => $this->transfer_number, 'status' => $this->status, 'source' => new InventoryLocationResource($this->whenLoaded('source')), 'destination' => new InventoryLocationResource($this->whenLoaded('destination')), 'items' => StockTransferItemResource::collection($this->whenLoaded('items'))];
    }
}
