<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'return_id' => $this->return?->uuid, 'payment_id' => $this->payment?->uuid, 'method' => $this->method, 'amount' => $this->amount, 'currency_code' => $this->currency_code, 'status' => $this->status, 'external_reference' => $this->external_reference, 'provider' => $this->provider, 'failure_reason' => $this->failure_reason, 'processed_at' => $this->processed_at];
    }
}
