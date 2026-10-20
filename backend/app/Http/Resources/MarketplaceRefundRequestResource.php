<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceRefundRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'order_id' => $this->order?->uuid, 'order_number' => $this->order?->order_number, 'requested_amount' => $this->requested_amount, 'approved_amount' => $this->approved_amount, 'currency_code' => $this->currency_code, 'reason' => $this->reason, 'status' => $this->status, 'provider' => $this->provider, 'provider_reference' => $this->provider_reference, 'review_note' => $this->review_note, 'reviewed_at' => $this->reviewed_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String()];
    }
}
