<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketplaceSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'seller_registration_enabled' => ['sometimes', 'boolean'],
            'seller_approval_required' => ['sometimes', 'boolean'],
            'listing_approval_required' => ['sometimes', 'boolean'],
            'maintenance_mode' => ['sometimes', 'boolean'],
            'currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'default_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'commission_base' => ['sometimes', Rule::in(['item_subtotal'])],
            'policies' => ['sometimes', 'array'],
            'revision' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
