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
            'tagline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'public_contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'public_contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'secondary_contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'business_category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'facebook_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'messaging_contact' => ['sometimes', 'nullable', 'string', 'max:100'],
            'shipping_coverage' => ['sometimes', 'nullable', 'string', 'max:255'],
            'support_contact' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cod_available' => ['sometimes', 'boolean'],
            'delivery_methods' => ['sometimes', 'array'],
            'delivery_methods.*' => ['string', 'max:80'],
            'operating_hours' => ['sometimes', 'array'],
            'operating_hours.*' => ['array'],
            'operating_hours.*.is_open' => ['sometimes', 'boolean'],
            'operating_hours.*.open' => ['sometimes', 'nullable', 'date_format:H:i'],
            'operating_hours.*.close' => ['sometimes', 'nullable', 'date_format:H:i'],
            'temporary_closure_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'holiday_notice' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'policies' => ['sometimes', 'array'],
            'policies.shipping' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'policies.returns' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'policies.refunds' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'policies.support' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
            'notification_preferences' => ['sometimes', 'array'],
            'notification_preferences.order_updates' => ['sometimes', 'boolean'],
            'notification_preferences.low_stock_alerts' => ['sometimes', 'boolean'],
            'fulfillment_preferences' => ['sometimes', 'array'],
            'fulfillment_preferences.delivery_type' => ['sometimes', 'nullable', Rule::in(['self_delivery', 'external_courier'])],
        ];
    }
}
