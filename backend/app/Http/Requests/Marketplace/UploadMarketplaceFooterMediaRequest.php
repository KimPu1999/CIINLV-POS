<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadMarketplaceFooterMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['logo', 'social_icon'])],
            'social_link_id' => ['nullable', 'string', 'max:80', 'required_if:kind,social_icon'],
            'image' => ['required', 'file', 'mimes:png,webp,svg', 'max:2048'],
        ];
    }
}
