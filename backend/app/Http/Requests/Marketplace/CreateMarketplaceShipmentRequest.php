<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class CreateMarketplaceShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_type' => ['required', 'in:self_delivery,external_courier,customer_pickup'],
            'carrier_name' => ['nullable', 'string', 'max:120', 'required_if:delivery_type,external_courier'],
            'tracking_number' => ['nullable', 'string', 'max:150', 'required_if:delivery_type,external_courier'],
            'tracking_url' => ['nullable', 'url', 'regex:/^https?:\\/\\//i', 'max:500'],
            'delivery_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
