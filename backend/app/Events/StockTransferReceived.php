<?php

namespace App\Events;

use App\Models\StockTransfer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockTransferReceived implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly StockTransfer $transfer) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->transfer->tenant->uuid.'.dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'stock-transfer.received';
    }

    public function broadcastWith(): array
    {
        return ['transfer_id' => $this->transfer->uuid, 'status' => $this->transfer->status];
    }
}
