<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class CreatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['supplier_id' => ['required', 'uuid'], 'destination_location_id' => ['required', 'uuid'], 'purchase_order_number' => ['required', 'string', 'max:60'], 'items' => ['required', 'array', 'min:1', 'max:500'], 'items.*.variant_id' => ['required', 'uuid'], 'items.*.ordered_quantity' => ['required', 'numeric', 'gt:0'], 'items.*.unit_cost' => ['required', 'numeric', 'min:0']];
    }
}
