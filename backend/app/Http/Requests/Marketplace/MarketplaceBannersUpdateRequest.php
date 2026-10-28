<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketplaceBannersUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'banners' => ['required', 'array'],
            'banners.pages' => ['required', 'array'],
            'banners.pages.*' => ['nullable', 'array'],
            'banners.pages.*.enabled' => ['required', 'boolean'],
            'banners.pages.*.auto_slide' => ['required', 'boolean'],
            'banners.pages.*.interval_ms' => ['required', 'integer', 'min:2000', 'max:30000'],
            'banners.pages.*.animation' => ['required', Rule::in(['slide', 'fade'])],
            'banners.pages.*.items' => ['nullable', 'array', 'max:20'],
            'banners.pages.*.items.*.id' => ['required', 'string', 'max:80'],
            'banners.pages.*.items.*.enabled' => ['required', 'boolean'],
            'banners.pages.*.items.*.title_en' => ['nullable', 'string', 'max:160'],
            'banners.pages.*.items.*.title_my' => ['nullable', 'string', 'max:160'],
            'banners.pages.*.items.*.subtitle_en' => ['nullable', 'string', 'max:320'],
            'banners.pages.*.items.*.subtitle_my' => ['nullable', 'string', 'max:320'],
            'banners.pages.*.items.*.cta_text_en' => ['nullable', 'string', 'max:80'],
            'banners.pages.*.items.*.cta_text_my' => ['nullable', 'string', 'max:80'],
            'banners.pages.*.items.*.cta_url' => ['nullable', 'string', 'max:500', 'regex:/^(\/|https?:\/\/)/i'],
            'banners.pages.*.items.*.desktop_image_path' => ['nullable', 'string', 'max:500'],
            'banners.pages.*.items.*.mobile_image_path' => ['nullable', 'string', 'max:500'],
            'banners.pages.*.items.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'banners.pages.*.items.*.starts_at' => ['nullable', 'date'],
            'banners.pages.*.items.*.ends_at' => ['nullable', 'date'],
        ];
    }
}
