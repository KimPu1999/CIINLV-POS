<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\TranslationService;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);
        return ['id' => $this->uuid, 'supplier_code' => $this->supplier_code, 'name' => $translations->localizedValue($this->resource, 'name', $locale), 'supplier_type' => $this->supplier_type, 'email' => $this->email, 'website' => $this->website, 'phone' => $this->phone, 'alternate_phone' => $this->alternate_phone, 'address' => $this->address, 'city' => $this->city, 'region' => $this->region, 'postal_code' => $this->postal_code, 'country' => $this->country, 'default_currency' => $this->default_currency, 'payment_terms' => $this->payment_terms, 'default_lead_time_days' => $this->default_lead_time_days, 'minimum_order_amount' => $this->minimum_order_amount, 'tax_registration_number' => $this->tax_registration_number, 'contact_person' => $this->contact_person, 'notes' => $translations->localizedValue($this->resource, 'notes', $locale), 'status' => $this->status, 'locale' => $locale, 'translation_status' => $translations->translationStatus($this->resource, $locale), 'purchase_order_count' => $this->when(isset($this->purchase_orders_count), (int) $this->purchase_orders_count), 'last_purchase_at' => $this->last_purchase_at ?? null];
    }
}
