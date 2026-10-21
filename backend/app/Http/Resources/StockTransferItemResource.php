<?php

namespace App\Http\Resources;

use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->variant?->product;
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);

        return ['variant_id' => $this->variant?->uuid, 'sku' => $this->variant?->sku, 'product' => $product ? $translations->localizedValue($product, 'name', $locale) : null, 'requested_quantity' => $this->requested_quantity, 'reserved_quantity' => $this->reserved_quantity, 'dispatched_quantity' => $this->dispatched_quantity, 'received_quantity' => $this->received_quantity, 'damaged_quantity' => $this->damaged_quantity, 'missing_quantity' => $this->missing_quantity, 'outstanding_quantity' => bcsub((string) $this->dispatched_quantity, bcadd(bcadd((string) $this->received_quantity, (string) $this->damaged_quantity, 3), (string) $this->missing_quantity, 3), 3)];
    }
}
