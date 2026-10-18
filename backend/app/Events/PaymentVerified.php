<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentVerified implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Payment $payment) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->payment->tenant->uuid.'.dashboard'), new PrivateChannel('tenant.'.$this->payment->tenant->uuid.'.store.'.$this->payment->store->uuid.'.sales')];
    }

    public function broadcastAs(): string
    {
        return 'payment.verified';
    }

    public function broadcastWith(): array
    {
        return ['payment_id' => $this->payment->uuid, 'sale_id' => $this->payment->sale->uuid, 'method' => $this->payment->method, 'amount' => (string) $this->payment->amount];
    }
}
