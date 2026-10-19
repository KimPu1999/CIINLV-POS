<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'parent_id' => $this->parent?->uuid, 'parent_name' => $this->parent?->name, 'name' => $this->name, 'slug' => $this->slug, 'description' => $this->description, 'image_path' => $this->image_path, 'status' => $this->status, 'sort_order' => $this->sort_order, 'product_count' => $this->when(isset($this->products_count), (int) $this->products_count), 'child_count' => $this->when(isset($this->children_count), (int) $this->children_count), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}
