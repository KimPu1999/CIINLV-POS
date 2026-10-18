<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'parent_id' => $this->parent?->uuid, 'name' => $this->name, 'slug' => $this->slug, 'description' => $this->description, 'image_path' => $this->image_path, 'status' => $this->status, 'sort_order' => $this->sort_order, 'created_at' => $this->created_at];
    }
}
