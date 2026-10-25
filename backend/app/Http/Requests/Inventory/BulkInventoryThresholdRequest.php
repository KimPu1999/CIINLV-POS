<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class BulkInventoryThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant_ids' => ['required', 'array', 'min:1', 'max:250'],
            'variant_ids.*' => ['required', 'uuid'],
            'reorder_level' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
