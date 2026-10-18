<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'receipt_number' => $this->receipt_number, 'sale_id' => $this->sale?->uuid, 'format' => $this->format, 'generated_at' => $this->generated_at];
    }
}
