<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'location_type' => $this->location_type, 'store_id' => $this->store?->uuid, 'name' => $this->name, 'code' => $this->code, 'address' => $this->address, 'status' => $this->status];
    }
}
