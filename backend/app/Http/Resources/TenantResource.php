<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'description' => $this->description,
            'business_type' => $this->business_type,
            'slug' => $this->slug,
            'business_code' => $this->business_code,
            'email' => $this->email,
            'website' => $this->website,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'email_verification_status' => $this->email_verification_status,
            'email_verified_at' => $this->email_verified_at,
            'subscription_status' => $this->subscription_status,
            'suspended_at' => $this->suspended_at,
            'archived_at' => $this->archived_at,
            'archived_by' => $this->archived_by,
            'created_at' => $this->created_at,
            'members_count' => $this->when(isset($this->memberships_count), (int) $this->memberships_count),
            'stores_count' => $this->when(isset($this->stores_count), (int) $this->stores_count),
            'owner' => $this->when($this->relationLoaded('memberships'), function (): ?array {
                $membership = $this->memberships->sortByDesc(fn ($item): int => $item->status === 'active' ? 1 : 0)->first();
                $owner = $membership?->user;

                return $owner ? ['name' => $owner->name, 'email' => $owner->email] : null;
            }),
            'owner_activation_status' => $this->when($this->relationLoaded('memberships'), function (): string {
                $membership = $this->memberships->sortByDesc(fn ($item): int => $item->status === 'active' ? 1 : 0)->first();
                $owner = $membership?->user;

                return $membership?->status === 'pending'
                    ? 'pending'
                    : ($owner?->email_verified_at ? 'verified' : ($this->email_verification_status ?: 'unknown'));
            }),
            'logo_url' => $this->logo_path ? route('business.settings.logo') : null,
        ];
    }
}
