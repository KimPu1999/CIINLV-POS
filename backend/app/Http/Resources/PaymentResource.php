<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'method' => $this->method, 'amount' => $this->amount, 'received_amount' => $this->received_amount, 'change_amount' => $this->change_amount, 'currency_code' => $this->currency_code, 'store_id' => $this->store?->uuid, 'register_id' => $this->register?->uuid, 'cashier_shift_id' => $this->cashier_shift_id, 'status' => $this->status, 'external_reference' => $this->external_reference, 'provider' => $this->provider, 'verified_at' => $this->verified_at, 'collected_by' => $this->collected_by];
    }
}
