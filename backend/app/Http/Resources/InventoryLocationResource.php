<?php

namespace App\Http\Resources;

use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);

        return ['id' => $this->uuid, 'location_type' => $this->location_type, 'store_id' => $this->store?->uuid, 'name' => $translations->localizedValue($this->resource, 'name', $locale), 'description' => $translations->localizedValue($this->resource, 'description', $locale), 'code' => $this->code, 'warehouse_type' => $this->warehouse_type, 'address' => $this->address, 'contact_phone' => $this->contact_phone, 'contact_email' => $this->contact_email, 'city' => $this->city, 'region' => $this->region, 'country' => $this->country, 'manager_user_id' => $this->manager_user_id, 'status' => $this->status, 'locale' => $locale, 'translation_status' => $translations->translationStatus($this->resource, $locale)];
    }
}
