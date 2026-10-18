<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosRegisterMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'register_id' => $this->register?->uuid, 'store_id' => $this->store?->uuid, 'user_id' => $this->user_id, 'user_name' => $this->user?->name, 'status' => $this->status, 'assigned_at' => $this->assigned_at, 'revoked_at' => $this->revoked_at];
    }
}
