<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'location_type' => $this->location_type, 'store_id' => $this->store?->uuid, 'name' => $this->name, 'description' => $this->description, 'code' => $this->code, 'warehouse_type' => $this->warehouse_type, 'address' => $this->address, 'contact_phone' => $this->contact_phone, 'contact_email' => $this->contact_email, 'city' => $this->city, 'region' => $this->region, 'country' => $this->country, 'manager_user_id' => $this->manager_user_id, 'status' => $this->status];
    }
}
