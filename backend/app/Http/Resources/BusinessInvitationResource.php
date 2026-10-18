<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->accepted_at ? 'accepted' : ($this->revoked_at ? 'revoked' : ($this->expires_at?->isPast() ? 'expired' : 'pending')),
            'email' => $this->email,
            'role' => $this->role,
            'expires_at' => $this->expires_at,
            'accepted_at' => $this->accepted_at,
        ];
    }
}
