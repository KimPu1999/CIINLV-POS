<?php

namespace App\Http\Resources;

use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'storage_path' => $this->storage_path, 'url' => PublicMediaUrl::make($this->storage_path), 'sort_order' => $this->sort_order, 'is_primary' => $this->is_primary];
    }
}
