<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'return_number' => $this->return_number, 'sale_id' => $this->sale?->uuid, 'sale_number' => $this->sale?->sale_number, 'store_id' => $this->store?->uuid, 'store_name' => $this->store?->name, 'status' => $this->status, 'reason' => $this->reason, 'refund_total' => $this->refund_total, 'requested_by' => $this->requester?->name ?? $this->requested_by, 'approved_by' => $this->approver?->name ?? $this->approved_by, 'requested_at' => $this->requested_at, 'approved_at' => $this->approved_at, 'refunded_at' => $this->refunded_at, 'items' => SaleReturnItemResource::collection($this->whenLoaded('items')), 'refund' => new RefundResource($this->whenLoaded('refund'))];
    }
}
