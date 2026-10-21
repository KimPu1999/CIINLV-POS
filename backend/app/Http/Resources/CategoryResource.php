<?php

namespace App\Http\Resources;

use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);

        return ['id' => $this->uuid, 'parent_id' => $this->parent?->uuid, 'parent_name' => $this->parent ? $translations->localizedValue($this->parent, 'name', $locale) : null, 'name' => $translations->localizedValue($this->resource, 'name', $locale), 'slug' => $this->slug, 'description' => $translations->localizedValue($this->resource, 'description', $locale), 'image_path' => $this->image_path, 'status' => $this->status, 'sort_order' => $this->sort_order, 'product_count' => $this->when(isset($this->products_count), (int) $this->products_count), 'child_count' => $this->when(isset($this->children_count), (int) $this->children_count), 'locale' => $locale, 'translation_status' => $translations->translationStatus($this->resource, $locale), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}
