<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceCancellationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'order_id' => $this->order?->uuid, 'order_number' => $this->order?->order_number, 'reason' => $this->reason, 'status' => $this->status, 'decision_note' => $this->decision_note, 'decided_at' => $this->decided_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String()];
    }
}
