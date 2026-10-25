<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class OpeningStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['location_id' => ['required', 'uuid'], 'variant_id' => ['required', 'uuid'], 'quantity' => ['required', 'numeric', 'gt:0'], 'unit_cost' => ['nullable', 'numeric', 'min:0'], 'reason' => ['nullable', 'string', 'max:500'], 'reference_number' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:1000']];
    }
}
