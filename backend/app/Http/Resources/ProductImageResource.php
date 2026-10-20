<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'storage_path' => $this->storage_path, 'url' => Storage::disk('public')->url($this->storage_path), 'sort_order' => $this->sort_order, 'is_primary' => $this->is_primary];
    }
}
