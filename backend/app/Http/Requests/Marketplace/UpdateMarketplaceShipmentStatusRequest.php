<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMarketplaceShipmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:shipped,in_transit,delivered,delivery_failed,returned,cancelled'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
