<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosRegisterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'name' => $this->name, 'code' => $this->code, 'status' => $this->status, 'store_id' => $this->store?->uuid, 'settings' => $this->settings];
    }
}
