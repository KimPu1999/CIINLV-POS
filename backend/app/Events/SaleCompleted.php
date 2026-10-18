<?php

namespace App\Events;

use App\Models\Sale;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SaleCompleted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Sale $sale) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->sale->tenant->uuid.'.dashboard'), new PrivateChannel('tenant.'.$this->sale->tenant->uuid.'.store.'.$this->sale->store->uuid.'.sales')];
    }

    public function broadcastAs(): string
    {
        return 'sale.completed';
    }

    public function broadcastWith(): array
    {
        return ['sale_id' => $this->sale->uuid, 'store_id' => $this->sale->store->uuid, 'grand_total' => (string) $this->sale->grand_total, 'completed_at' => $this->sale->completed_at?->toIso8601String()];
    }
}
