<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceCancellationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $history = $this->order?->relationLoaded('events')
            ? $this->order->events->filter(fn ($event): bool => (((array) $event->metadata)['request_id'] ?? null) === $this->uuid || str_contains((string) $event->event, 'cancellation'))->map(fn ($event): array => [
                'id' => $event->id,
                'event' => $event->event,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'metadata' => $event->metadata,
                'actor_id' => $event->actor_user_id,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all()
            : null;

        return [
            'id' => $this->uuid,
            'order_id' => $this->order?->uuid,
            'order_number' => $this->order?->order_number,
            'reason' => $this->reason,
            'status' => $this->status,
            'decision_note' => $this->decision_note,
            'decided_by' => $this->decider?->only(['id', 'name', 'email']),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'history' => $history,
        ];
    }
}
