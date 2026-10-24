<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketplaceAppearanceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        $colors = ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'];
        $sizes = ['nullable', 'numeric', 'min:10', 'max:48'];

        return [
            'appearance' => ['required', 'array'],
            'appearance.font_family' => ['required', Rule::in(['Inter', 'system-ui', 'Nunito Sans', 'Noto Sans'])],
            'appearance.nav_font_size' => $sizes,
            'appearance.nav_font_weight' => ['nullable', Rule::in(['500', '600', '700'])],
            'appearance.body_font_size' => $sizes,
            'appearance.heading_font_size' => $sizes,
            'appearance.button_font_size' => $sizes,
            'appearance.footer_font_size' => $sizes,
            'appearance.light' => ['required', 'array'],
            'appearance.dark' => ['required', 'array'],
        ] + collect(['primary', 'secondary', 'accent', 'header_bg', 'nav_text', 'nav_hover', 'nav_active', 'button_bg', 'button_text', 'page_bg', 'card_bg', 'border', 'footer_bg', 'footer_text'])
            ->flatMap(fn (string $key): array => ["appearance.light.$key" => $colors, "appearance.dark.$key" => $colors])
            ->all();
    }
}
