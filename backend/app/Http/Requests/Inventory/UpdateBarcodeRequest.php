<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBarcodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'barcode' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['ean-13', 'ean-8', 'upc-a', 'code-128', 'internal', 'manual'])],
        ];
    }
}
