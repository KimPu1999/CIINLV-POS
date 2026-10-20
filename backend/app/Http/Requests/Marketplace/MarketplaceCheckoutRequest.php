<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class MarketplaceCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address_id' => ['required', 'integer', 'exists:customer_addresses,id'],
            'payment_method' => ['required', 'in:cod'],
            'delivery_options' => ['sometimes', 'array', 'max:100'],
            'delivery_options.*' => ['sometimes', 'array', 'max:5'],
            'delivery_options.*.method' => ['sometimes', 'string', 'max:40'],
        ];
    }
}
