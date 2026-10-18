<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['location_id' => ['required', 'uuid'], 'variant_id' => ['required', 'uuid'], 'quantity_delta' => ['required', 'numeric', 'not_in:0'], 'reason' => ['required', 'string', 'max:500']];
    }
}
