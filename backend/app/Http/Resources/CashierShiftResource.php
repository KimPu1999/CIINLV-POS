<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashierShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'store_id' => $this->store?->uuid, 'register_id' => $this->register?->uuid, 'cashier_user_id' => $this->cashier_user_id, 'opening_amount' => $this->opening_amount, 'closing_amount' => $this->closing_amount, 'expected_amount' => $this->expected_amount, 'actual_amount' => $this->actual_amount, 'cash_difference' => $this->cash_difference, 'status' => $this->status, 'opened_at' => $this->opened_at, 'closed_at' => $this->closed_at];
    }
}
