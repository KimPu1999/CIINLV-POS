<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplacePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'method' => $this->method, 'provider' => $this->provider, 'status' => $this->status, 'amount' => $this->amount, 'currency_code' => $this->currency_code, 'provider_reference' => $this->provider_reference, 'paid_at' => $this->paid_at?->toIso8601String()];
    }
}
