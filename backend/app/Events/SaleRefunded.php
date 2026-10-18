<?php

namespace App\Events;

use App\Models\Refund;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SaleRefunded implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Refund $refund) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->refund->tenant->uuid.'.dashboard'), new PrivateChannel('tenant.'.$this->refund->tenant->uuid.'.store.'.$this->refund->store->uuid.'.sales')];
    }

    public function broadcastAs(): string
    {
        return 'sale.refunded';
    }

    public function broadcastWith(): array
    {
        return ['refund_id' => $this->refund->uuid, 'sale_id' => $this->refund->sale->uuid, 'amount' => (string) $this->refund->amount];
    }
}
