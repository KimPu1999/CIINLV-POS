<?php

namespace App\Http\Resources;

use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MarketplaceCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);

        return [
            'id' => $this->uuid,
            'name' => $translations->localizedValue($this->resource, 'name', $locale),
            'slug' => $this->slug,
            'description' => $translations->localizedValue($this->resource, 'description', $locale),
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'product_count' => $this->when(isset($this->public_products_count), (int) $this->public_products_count),
            'locale' => $locale,
        ];
    }
}
