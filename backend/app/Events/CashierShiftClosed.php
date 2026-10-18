<?php

namespace App\Events;

use App\Models\CashierShift;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CashierShiftClosed implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly CashierShift $shift) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->shift->tenant->uuid.'.dashboard'), new PrivateChannel('tenant.'.$this->shift->tenant->uuid.'.store.'.$this->shift->store->uuid.'.sales')];
    }

    public function broadcastAs(): string
    {
        return 'cashier-shift.closed';
    }

    public function broadcastWith(): array
    {
        return ['shift_id' => $this->shift->uuid, 'store_id' => $this->shift->store->uuid, 'cash_difference' => (string) $this->shift->cash_difference];
    }
}
