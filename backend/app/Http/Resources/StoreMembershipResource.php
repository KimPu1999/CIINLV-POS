<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'user' => new UserResource($this->whenLoaded('user')), 'role' => $this->role, 'status' => $this->status, 'assigned_at' => $this->assigned_at, 'revoked_at' => $this->revoked_at];
    }
}
