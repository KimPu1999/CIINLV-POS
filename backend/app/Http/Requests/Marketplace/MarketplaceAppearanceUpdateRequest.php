<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MarketplaceAppearanceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        $colors = ['required', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'];
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
            'appearance.footer' => ['required', 'array'],
            'appearance.footer.show_social_icons' => ['required', 'boolean'],
            'appearance.footer.facebook_url' => ['nullable', 'string', 'max:255'],
            'appearance.footer.instagram_url' => ['nullable', 'string', 'max:255'],
            'appearance.footer.youtube_url' => ['nullable', 'string', 'max:255'],
            'appearance.footer.tiktok_url' => ['nullable', 'string', 'max:255'],
            'appearance.footer.description_en' => ['required', 'string', 'max:500'],
            'appearance.footer.description_my' => ['required', 'string', 'max:500'],
        ] + collect(['primary', 'secondary', 'accent', 'header_bg', 'nav_text', 'nav_hover', 'nav_active', 'button_bg', 'button_text', 'page_bg', 'card_bg', 'border', 'footer_bg', 'footer_text'])
            ->flatMap(fn (string $key): array => ["appearance.light.$key" => $colors, "appearance.dark.$key" => $colors])
            ->all();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['facebook_url', 'instagram_url', 'youtube_url', 'tiktok_url'] as $provider) {
                $value = $this->input("appearance.footer.$provider");
                if ($value === null || $value === '') {
                    continue;
                }

                $url = filter_var($value, FILTER_VALIDATE_URL);
                $host = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_HOST)) : '';
                $allowed = match ($provider) {
                    'facebook_url' => ['facebook.com', 'www.facebook.com', 'fb.com', 'www.fb.com'],
                    'instagram_url' => ['instagram.com', 'www.instagram.com'],
                    'youtube_url' => ['youtube.com', 'www.youtube.com', 'youtu.be'],
                    'tiktok_url' => ['tiktok.com', 'www.tiktok.com'],
                };

                if (! is_string($url) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || ! in_array($host, $allowed, true)) {
                    $validator->errors()->add("appearance.footer.$provider", 'Use a valid HTTPS URL from the approved social network domain.');
                }
            }
        });
    }
}
