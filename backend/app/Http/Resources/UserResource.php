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
            'updated_at' => $this->updated_at,
            'profile_image_url' => $this->profile_image_path
                ? route('me.profile-image', [], false).'?v='.$this->updated_at?->timestamp
                : null,
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
                        'logo_url' => $membership->tenant->logo_path
                            ? route('me.business-logo', ['tenant' => $membership->tenant->uuid], false).'?v='.$membership->tenant->updated_at?->timestamp
                            : null,
                    ] : null,
                ])
                ->filter(static fn (array $membership): bool => $membership['business'] !== null)
                ->values()
                ->all()),
        ];
    }
}
