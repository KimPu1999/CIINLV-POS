<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class AddMarketplaceCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'listing_id' => ['required', 'uuid'],
            'variant_id' => ['nullable', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
        ];
    }
}
