<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->customerProfile;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $profile?->phone,
            'status' => $profile?->status,
            'locale' => $this->locale ?: 'en',
            'email_verified_at' => $this->email_verified_at,
            'addresses' => $this->when($profile?->relationLoaded('addresses'), CustomerAddressResource::collection($profile->addresses)),
        ];
    }
}
