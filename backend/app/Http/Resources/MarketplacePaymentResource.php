<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplacePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'method' => $this->method,
            'provider' => $this->provider,
            'status' => $this->status,
            'amount' => $this->amount,
            'amount_received' => $this->amount_received,
            'change_amount' => $this->change_amount,
            'currency_code' => $this->currency_code,
            'provider_reference' => $this->provider_reference,
            'transaction_reference' => $this->transaction_reference,
            'receipt_number' => $this->receipt_number,
            'received_at' => $this->received_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->only(['id', 'name', 'email'])),
        ];
    }
}
