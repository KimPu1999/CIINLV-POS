<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'supplier_code' => $this->supplier_code, 'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone, 'address' => $this->address, 'contact_person' => $this->contact_person, 'notes' => $this->notes, 'status' => $this->status];
    }
}
