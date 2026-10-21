<?php

namespace App\Http\Resources;

use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);
        $platformViewer = $request->user()?->isSuperAdmin() ?? false;
        $documentUrl = function (?string $path, string $document) use ($platformViewer): ?string {
            if (! $path) {
                return null;
            }

            return $platformViewer
                ? route('platform.businesses.verification', [$this->uuid, $document])
                : route('business.verification.document', $document);
        };

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'display_name' => $translations->localizedValue($this->resource, 'display_name', $locale),
            'description' => $translations->localizedValue($this->resource, 'description', $locale),
            'business_type' => $translations->localizedValue($this->resource, 'business_type', $locale),
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'company_registration_number' => $this->company_registration_number,
            'personal_registration_number' => $this->personal_registration_number,
            'verification_status' => $this->verification_status,
            'locale' => $locale,
            'translation_status' => $translations->translationStatus($this->resource, $locale),
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
            'logo_url' => $this->logo_path ? ($platformViewer ? route('platform.businesses.verification', [$this->uuid, 'business_logo']) : route('me.business-logo', ['tenant' => $this->uuid], false)) : null,
            'admin_profile_image_url' => $platformViewer ? null : ($request->user()?->profile_image_path ? route('me.profile-image', [], false) : null),
            'verification_document_urls' => [
                'id_front_image' => $documentUrl($this->id_front_image_path, 'id_front_image'),
                'id_back_image' => $documentUrl($this->id_back_image_path, 'id_back_image'),
                'company_certificate_image' => $documentUrl($this->company_certificate_image_path, 'company_certificate_image'),
            ],
        ];
    }
}
