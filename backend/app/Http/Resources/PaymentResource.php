<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'method' => $this->method, 'amount' => $this->amount, 'currency_code' => $this->currency_code, 'status' => $this->status, 'external_reference' => $this->external_reference, 'provider' => $this->provider, 'verified_at' => $this->verified_at, 'collected_by' => $this->collected_by];
    }
}
