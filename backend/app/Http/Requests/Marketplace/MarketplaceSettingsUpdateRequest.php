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
            'payout_schedule' => ['sometimes', Rule::in(['manual', 'weekly', 'biweekly', 'monthly'])],
            'payout_minimum' => ['sometimes', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'policies' => ['sometimes', 'array'],
            'qr_payment_provider' => ['sometimes', 'nullable', 'string', 'max:80'],
            'qr_payment_account_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'qr_payment_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'qr_payment_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'qr_payment_enabled' => ['sometimes', 'boolean'],
            'revision' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
