<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\TranslationService;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->variant?->product;
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);
        return ['variant_id' => $this->variant?->uuid, 'sku' => $this->variant?->sku, 'product' => $product ? $translations->localizedValue($product, 'name', $locale) : null, 'ordered_quantity' => $this->ordered_quantity, 'received_quantity' => $this->received_quantity, 'remaining_quantity' => bcsub((string) $this->ordered_quantity, (string) $this->received_quantity, 3), 'unit_cost' => $this->unit_cost, 'line_total' => number_format((float) $this->ordered_quantity * (float) $this->unit_cost, 2, '.', '')];
    }
}
