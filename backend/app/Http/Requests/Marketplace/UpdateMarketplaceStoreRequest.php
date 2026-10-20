<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMarketplaceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'alpha_dash', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'public_contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'public_contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'business_category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'notification_preferences' => ['sometimes', 'array'],
            'notification_preferences.order_updates' => ['sometimes', 'boolean'],
            'notification_preferences.low_stock_alerts' => ['sometimes', 'boolean'],
            'fulfillment_preferences' => ['sometimes', 'array'],
            'fulfillment_preferences.delivery_type' => ['sometimes', 'nullable', Rule::in(['self_delivery', 'external_courier'])],
        ];
    }
}
