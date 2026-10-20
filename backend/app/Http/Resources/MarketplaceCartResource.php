<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceCartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'currency_code' => $this->currency_code,
            'status' => $this->status,
            'items' => MarketplaceCartItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
