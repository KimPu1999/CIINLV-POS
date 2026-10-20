<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['source_locale' => ['sometimes', 'in:en,my'], 'supplier_code' => ['sometimes', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'], 'name' => ['sometimes', 'string', 'max:200'], 'supplier_type' => ['nullable', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:255'], 'website' => ['nullable', 'url', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'alternate_phone' => ['nullable', 'string', 'max:50'], 'address' => ['nullable', 'string', 'max:2000'], 'city' => ['nullable', 'string', 'max:100'], 'region' => ['nullable', 'string', 'max:100'], 'postal_code' => ['nullable', 'string', 'max:30'], 'country' => ['nullable', 'string', 'max:100'], 'default_currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'], 'payment_terms' => ['nullable', 'string', 'max:100'], 'default_lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'minimum_order_amount' => ['nullable', 'numeric', 'min:0'], 'tax_registration_number' => ['nullable', 'string', 'max:100'], 'contact_person' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:5000'], 'status' => ['sometimes', 'in:active,inactive,archived']];
    }
}
