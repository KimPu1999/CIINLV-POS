<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'platform_role' => $this->platform_role,
            'status' => $this->status,
            'locale' => $this->locale ?: 'en',
            'email_verified_at' => $this->email_verified_at,
            'memberships' => $this->whenLoaded('memberships', fn (): array => $this->memberships
                ->map(static fn ($membership): array => [
                    'id' => $membership->id,
                    'status' => $membership->status,
                    'role' => $membership->role?->name,
                    'joined_at' => $membership->joined_at,
                    'business' => $membership->tenant ? [
                        'id' => $membership->tenant->uuid,
                        'name' => $membership->tenant->name,
                        'business_code' => $membership->tenant->business_code,
                        'status' => $membership->tenant->status,
                        'subscription_status' => $membership->tenant->subscription_status,
                    ] : null,
                ])
                ->filter(static fn (array $membership): bool => $membership['business'] !== null)
                ->values()
                ->all()),
        ];
    }
}
