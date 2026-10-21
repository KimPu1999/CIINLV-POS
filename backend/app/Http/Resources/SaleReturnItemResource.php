<?php

namespace App\Http\Resources;

use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->variant?->product;
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);

        return ['id' => $this->uuid, 'sale_item_id' => $this->saleItem?->uuid, 'variant_id' => $this->variant?->uuid, 'product_name' => $this->saleItem?->product_name_snapshot, 'product_name_localized' => $product ? $translations->localizedValue($product, 'name', $locale) : $this->saleItem?->product_name_snapshot, 'sku' => $this->saleItem?->sku_snapshot, 'quantity' => $this->quantity, 'unit_refund_amount' => $this->unit_refund_amount, 'refund_amount' => $this->refund_amount, 'restock' => $this->restock, 'disposition' => $this->disposition];
    }
}
