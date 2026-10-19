<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'status' => $this->status(),
            'email' => $this->email,
            'role' => $this->role,
            'expires_at' => $this->expires_at,
            'sent_at' => $this->sent_at,
            'failed_at' => $this->failed_at,
            'accepted_at' => $this->accepted_at,
            'revoked_at' => $this->revoked_at,
            'password_setup_required' => $this->relationLoaded('user')
                ? ! $this->user?->isActive()
                : null,
            'business' => $this->relationLoaded('tenant') && $this->tenant
                ? [
                    'id' => $this->tenant->uuid,
                    'name' => $this->tenant->name,
                    'business_code' => $this->tenant->business_code,
                    'status' => $this->tenant->status,
                ]
                : null,
        ];

        if ($request->user()?->isSuperAdmin()) {
            $data['failure_reason'] = $this->failure_reason;
        }

        return $data;
    }
}
