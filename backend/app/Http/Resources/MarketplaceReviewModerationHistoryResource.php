<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceReviewModerationHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'previous_status' => $this->previous_status,
            'new_status' => $this->new_status,
            'reason' => $this->reason,
            'moderator' => $this->moderator?->only(['id', 'name']),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
