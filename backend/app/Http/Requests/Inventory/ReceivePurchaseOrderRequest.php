<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['items' => ['required', 'array', 'min:1', 'max:500'], 'items.*.variant_id' => ['required', 'uuid'], 'items.*.quantity_received' => ['required', 'numeric', 'gt:0'], 'items.*.unit_cost' => ['nullable', 'numeric', 'min:0']];
    }
}
