<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketplaceListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'product_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'uuid'],
            'slug' => [$this->isMethod('post') ? 'nullable' : 'sometimes', 'string', 'alpha_dash', 'max:150'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
        ];
    }
}
