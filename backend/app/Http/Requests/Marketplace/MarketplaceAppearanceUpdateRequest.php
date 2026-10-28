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
            'appearance.footer.logo_path' => ['nullable', 'string', 'max:500'],
            'appearance.footer.facebook_url' => ['nullable', 'string', 'max:255'],
            'appearance.footer.instagram_url' => ['nullable', 'string', 'max:255'],
            'appearance.footer.youtube_url' => ['nullable', 'string', 'max:255'],
            'appearance.footer.tiktok_url' => ['nullable', 'string', 'max:255'],
            'appearance.footer.description_en' => ['required', 'string', 'max:500'],
            'appearance.footer.description_my' => ['required', 'string', 'max:500'],
            'appearance.footer.social_links' => ['nullable', 'array', 'max:20'],
            'appearance.footer.social_links.*.id' => ['required', 'string', 'max:80'],
            'appearance.footer.social_links.*.platform' => ['required', Rule::in(['facebook', 'instagram', 'youtube', 'tiktok', 'x', 'telegram', 'whatsapp', 'custom'])],
            'appearance.footer.social_links.*.url' => ['required', 'string', 'max:500', 'regex:/^https:\/\//i'],
            'appearance.footer.social_links.*.enabled' => ['required', 'boolean'],
            'appearance.footer.social_links.*.icon_mode' => ['nullable', Rule::in(['default', 'custom'])],
            'appearance.footer.social_links.*.custom_icon_path' => ['nullable', 'string', 'max:500'],
            'appearance.footer.social_links.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'appearance.discover_sections' => ['nullable', 'array', 'max:6'],
            'appearance.discover_sections.*.page' => ['required', 'distinct', Rule::in(['home', 'shop', 'categories', 'stores', 'about', 'contact'])],
            'appearance.discover_sections.*.enabled' => ['required', 'boolean'],
            'appearance.discover_sections.*.eyebrow_en' => ['nullable', 'string', 'max:80'],
            'appearance.discover_sections.*.eyebrow_my' => ['nullable', 'string', 'max:80'],
            'appearance.discover_sections.*.title_en' => ['required', 'string', 'max:160'],
            'appearance.discover_sections.*.title_my' => ['nullable', 'string', 'max:160'],
            'appearance.discover_sections.*.body_en' => ['nullable', 'string', 'max:320'],
            'appearance.discover_sections.*.body_my' => ['nullable', 'string', 'max:320'],
            'appearance.discover_sections.*.cta_label_en' => ['nullable', 'string', 'max:80'],
            'appearance.discover_sections.*.cta_label_my' => ['nullable', 'string', 'max:80'],
            'appearance.discover_sections.*.cta_url' => ['nullable', 'string', 'max:500', 'regex:/^(\/|https?:\/\/)/i'],
            'appearance.discover_sections.*.layout' => ['nullable', Rule::in(['grid', 'carousel'])],
            'appearance.banners' => ['nullable', 'array'],
            'appearance.banners.settings' => ['nullable', 'array'],
            'appearance.banners.settings.auto_slide' => ['nullable', 'boolean'],
            'appearance.banners.settings.interval_ms' => ['nullable', 'integer', 'min:2000', 'max:30000'],
            'appearance.banners.settings.animation' => ['nullable', Rule::in(['slide', 'fade'])],
            'appearance.banners.pages' => ['nullable', 'array'],
            'appearance.banners.pages.*' => ['nullable', 'array'],
            'appearance.banners.pages.*.enabled' => ['nullable', 'boolean'],
            'appearance.banners.pages.*.auto_slide' => ['nullable', 'boolean'],
            'appearance.banners.pages.*.interval_ms' => ['nullable', 'integer', 'min:3000', 'max:8000'],
            'appearance.banners.pages.*.animation' => ['nullable', Rule::in(['slide', 'fade'])],
            'appearance.banners.pages.*.items' => ['nullable', 'array', 'max:7'],
            'appearance.banners.pages.*.items.*.id' => ['required', 'string', 'max:80'],
            'appearance.banners.pages.*.items.*.enabled' => ['required', 'boolean'],
            'appearance.banners.pages.*.items.*.title_en' => ['nullable', 'string', 'max:160'],
            'appearance.banners.pages.*.items.*.title_my' => ['nullable', 'string', 'max:160'],
            'appearance.banners.pages.*.items.*.subtitle_en' => ['nullable', 'string', 'max:320'],
            'appearance.banners.pages.*.items.*.subtitle_my' => ['nullable', 'string', 'max:320'],
            'appearance.banners.pages.*.items.*.cta_text_en' => ['nullable', 'string', 'max:80'],
            'appearance.banners.pages.*.items.*.cta_text_my' => ['nullable', 'string', 'max:80'],
            'appearance.banners.pages.*.items.*.cta_url' => ['nullable', 'string', 'max:500', 'regex:/^(\/|https?:\/\/)/i'],
            'appearance.banners.pages.*.items.*.alt_text_en' => ['nullable', 'string', 'max:160'],
            'appearance.banners.pages.*.items.*.alt_text_my' => ['nullable', 'string', 'max:160'],
            'appearance.banners.pages.*.items.*.text_align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'appearance.banners.pages.*.items.*.overlay' => ['nullable', Rule::in(['dark', 'gradient', 'none'])],
            'appearance.banners.pages.*.items.*.desktop_image_path' => ['nullable', 'string', 'max:500'],
            'appearance.banners.pages.*.items.*.mobile_image_path' => ['nullable', 'string', 'max:500'],
            'appearance.banners.pages.*.items.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'appearance.banners.pages.*.items.*.starts_at' => ['nullable', 'date'],
            'appearance.banners.pages.*.items.*.ends_at' => ['nullable', 'date', 'after_or_equal:appearance.banners.pages.*.items.*.starts_at'],
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
