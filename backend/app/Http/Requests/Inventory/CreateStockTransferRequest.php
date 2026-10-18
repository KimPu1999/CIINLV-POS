<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class CreateStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['source_location_id' => ['required', 'uuid'], 'destination_location_id' => ['required', 'uuid', 'different:source_location_id'], 'transfer_number' => ['required', 'string', 'max:60'], 'items' => ['required', 'array', 'min:1', 'max:500'], 'items.*.variant_id' => ['required', 'uuid'], 'items.*.requested_quantity' => ['required', 'numeric', 'gt:0']];
    }
}
