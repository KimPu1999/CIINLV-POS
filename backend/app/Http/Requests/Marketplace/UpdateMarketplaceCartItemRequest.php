<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMarketplaceCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['quantity' => ['required', 'numeric', 'gt:0', 'max:999999'], 'variant_id' => ['sometimes', 'nullable', 'uuid']];
    }
}
