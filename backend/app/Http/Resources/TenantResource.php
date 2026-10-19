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
            'logo_url' => $this->logo_path ? route('business.settings.logo') : null,
        ];
    }
}
