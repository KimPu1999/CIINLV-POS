<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'return_number' => $this->return_number, 'sale_id' => $this->sale?->uuid, 'store_id' => $this->store?->uuid, 'status' => $this->status, 'reason' => $this->reason, 'refund_total' => $this->refund_total, 'requested_by' => $this->requested_by, 'approved_by' => $this->approved_by, 'items' => SaleReturnItemResource::collection($this->whenLoaded('items')), 'refund' => new RefundResource($this->whenLoaded('refund'))];
    }
}
