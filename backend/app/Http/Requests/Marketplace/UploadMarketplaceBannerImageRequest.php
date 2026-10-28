<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadMarketplaceBannerImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'page' => ['required', Rule::in(['home', 'shop', 'categories', 'stores', 'about', 'contact'])],
            'banner_id' => ['required', 'string', 'max:80'],
            'device' => ['required', Rule::in(['desktop', 'mobile'])],
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }
}
