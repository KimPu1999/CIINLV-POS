<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkGenerateBarcodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant_ids' => ['required', 'array', 'min:1', 'max:100'],
            'variant_ids.*' => ['required', 'uuid'],
            'type' => ['nullable', Rule::in(['code-128', 'internal'])],
            'preview' => ['sometimes', 'boolean'],
            'assignments' => ['sometimes', 'array', 'max:100'],
            'assignments.*.variant_id' => ['required_with:assignments', 'uuid'],
            'assignments.*.barcode' => ['required_with:assignments', 'string', 'max:100'],
        ];
    }
}
