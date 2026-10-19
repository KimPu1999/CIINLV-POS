<?php

namespace App\Events;

use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\SerializesModels;

#[Tries(5)]
#[Backoff(5, 30, 120)]
class StockUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly InventoryLocation $location, public readonly ProductVariant $variant, public readonly string $quantityAfter) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->location->tenant->uuid.'.dashboard'), ...($this->location->store ? [new PrivateChannel('tenant.'.$this->location->tenant->uuid.'.store.'.$this->location->store->uuid.'.inventory')] : [])];
    }

    public function broadcastAs(): string
    {
        return 'stock.updated';
    }

    public function broadcastWith(): array
    {
        return ['location_id' => $this->location->uuid, 'variant_id' => $this->variant->uuid, 'quantity_after' => $this->quantityAfter];
    }
}
