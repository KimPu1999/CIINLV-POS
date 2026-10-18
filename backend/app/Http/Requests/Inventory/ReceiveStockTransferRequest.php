<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['items' => ['required', 'array', 'min:1'], 'items.*.variant_id' => ['required', 'uuid'], 'items.*.received_quantity' => ['nullable', 'numeric', 'min:0'], 'items.*.damaged_quantity' => ['nullable', 'numeric', 'min:0'], 'items.*.missing_quantity' => ['nullable', 'numeric', 'min:0']];
    }
}
