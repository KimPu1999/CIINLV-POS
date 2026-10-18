<?php

namespace App\Events;

use App\Models\StockAlert;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LowStockTriggered implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly StockAlert $alert) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->alert->tenant->uuid.'.dashboard'), new PrivateChannel('tenant.'.$this->alert->tenant->uuid.'.alerts')];
    }

    public function broadcastAs(): string
    {
        return 'low-stock.triggered';
    }

    public function broadcastWith(): array
    {
        return ['alert_id' => $this->alert->uuid, 'alert_type' => $this->alert->alert_type, 'location_id' => $this->alert->location->uuid, 'variant_id' => $this->alert->variant->uuid];
    }
}
