<?php

namespace App\Http\Resources;

use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'product' => [
                'id' => $this->listing?->uuid,
                'name' => $this->product?->name ?? $this->orderItem?->product_name_snapshot,
                'image_url' => $this->listing?->product?->images?->first()?->storage_path ? PublicMediaUrl::make($this->listing->product->images->first()->storage_path) : null,
            ],
            'customer' => [
                'id' => $this->customer?->id,
                'name' => $this->customer?->name,
            ],
            'order_item_id' => $this->orderItem?->uuid,
            'rating' => (int) $this->rating,
            'title' => $this->title,
            'comment' => $this->comment,
            'status' => $this->status,
            'verified_purchase' => (bool) $this->verified_purchase,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'moderation_reason' => $this->moderation_reason,
            'moderated_at' => $this->moderated_at?->toIso8601String(),
            'moderator' => $this->moderator?->only(['id', 'name']),
            'moderation_history' => MarketplaceReviewModerationHistoryResource::collection($this->whenLoaded('moderationHistory')),
        ];
    }
}
