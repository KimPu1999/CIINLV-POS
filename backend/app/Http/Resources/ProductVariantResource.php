<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'sku' => $this->sku, 'barcode' => $this->barcode, 'barcode_type' => $this->barcode_type, 'unit' => $this->unit, 'attributes' => $this->attributes, 'purchase_cost' => $this->purchase_cost, 'selling_price' => $this->selling_price, 'reorder_level' => $this->reorder_level, 'status' => $this->status];
    }
}
